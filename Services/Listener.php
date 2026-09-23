<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\Websocket\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;

/**
 * Long-running relay listener: keeps one websocket per (mailbox, inbox relay),
 * subscribes to gift wraps addressed to the mailbox key and hands them to the
 * IncomingMessageHandler. Reconnects with backoff, answers NIP-42 AUTH, picks up
 * settings changes and exits after its lifetime so the scheduler restarts it.
 */
class Listener
{
    const TICK = 30;
    const MIN_BACKOFF = 5;
    const MAX_BACKOFF = 300;

    /** @var IncomingMessageHandler */
    protected $handler;

    /** @var callable|null */
    protected $logger;

    /** @var int */
    protected $lifetime;

    protected $loop;
    protected $connector;
    protected $startedAt;
    protected $stopping = false;
    protected $fingerprint = '';

    /** @var array key => state */
    protected $connections = [];

    public function __construct(IncomingMessageHandler $handler, $lifetime = 1200, ?callable $logger = null)
    {
        $this->handler = $handler;
        $this->lifetime = max(60, (int) $lifetime);
        $this->logger = $logger;
    }

    /**
     * Stay connected until the lifetime is over or a signal arrives.
     */
    public function run()
    {
        $this->startedAt = time();
        $this->loop = Loop::get();
        $this->connector = new Connector($this->loop, new \React\Socket\Connector(['timeout' => 20], $this->loop));

        $this->reload();

        $this->loop->addPeriodicTimer(self::TICK, function () {
            $this->tick();
        });

        if (function_exists('pcntl_signal') && defined('SIGTERM')) {
            foreach ([SIGTERM, SIGINT] as $signal) {
                $this->loop->addSignal($signal, function () {
                    $this->log('signal received, stopping');
                    $this->stop();
                });
            }
        }

        $this->log('listener started (lifetime '.$this->lifetime.'s)');
        $this->loop->run();
        $this->log('listener stopped');
    }

    /**
     * One synchronous pass: fetch pending wraps from every relay and exit.
     */
    public function runOnce()
    {
        $count = 0;
        foreach (NostrMailbox::active() as $cfg) {
            $client = new RelayClient(RelayClient::authSignerForKey($cfg->getPrivateKey()), $this->logger);
            $events = $client->fetch([$this->filter($cfg)], $cfg->getInboxRelays(), null, false);
            $this->log(sprintf('mailbox %d: %d gift wrap(s) on %d relay(s)', $cfg->mailbox_id, count($events), count($cfg->getInboxRelays())));
            foreach ($events as $event) {
                try {
                    if ($this->handler->handleGiftWrap($cfg, $event, null)) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    $this->log('error handling event: '.$e->getMessage());
                    \Log::error('[Nostr] '.$e->getMessage(), ['exception' => $e]);
                }
            }
        }

        return $count;
    }

    public function stop()
    {
        $this->stopping = true;
        foreach (array_keys($this->connections) as $key) {
            $this->drop($key);
        }
        if ($this->loop) {
            $this->loop->stop();
        }
    }

    protected function filter(NostrMailbox $cfg)
    {
        return [
            'kinds' => [GiftWrap::KIND_WRAP],
            '#p' => [$cfg->pubkey],
            'since' => time() - (int) config('nostr.lookback', 3 * 86400),
        ];
    }

    /**
     * Open or close connections to match the enabled mailboxes and their inbox relays.
     */
    protected function reload()
    {
        $wanted = [];
        $this->fingerprint = '';
        $parts = [];

        foreach (NostrMailbox::active() as $cfg) {
            $parts[] = $cfg->getListenerFingerprint();
            foreach ($cfg->getInboxRelays() as $url) {
                $key = $cfg->id.'|'.$url;
                $wanted[$key] = true;

                if (isset($this->connections[$key])) {
                    $current = $this->connections[$key]['cfg'];
                    if ($current->getListenerFingerprint() === $cfg->getListenerFingerprint()) {
                        $this->connections[$key]['cfg'] = $cfg;
                        continue;
                    }
                    $this->drop($key);
                }

                $this->connections[$key] = [
                    'cfg' => $cfg,
                    'url' => $url,
                    'conn' => null,
                    'backoff' => 0,
                    'sub' => null,
                    'challenge' => null,
                    'authed' => false,
                    'auth_event_id' => null,
                    'timer' => null,
                ];
                $this->connect($key);
            }
        }
        $this->fingerprint = md5(implode('|', $parts));

        foreach (array_keys($this->connections) as $key) {
            if (!isset($wanted[$key])) {
                $this->drop($key);
            }
        }

        if (!$wanted) {
            $this->log('no enabled Nostr mailboxes, waiting');
        }
    }

    protected function currentFingerprint()
    {
        $parts = [];
        foreach (NostrMailbox::active() as $cfg) {
            $parts[] = $cfg->getListenerFingerprint();
        }

        return md5(implode('|', $parts));
    }

    protected function connect($key)
    {
        if ($this->stopping || !isset($this->connections[$key])) {
            return;
        }
        $url = $this->connections[$key]['url'];
        $this->log('connecting to '.$url.' for mailbox '.$this->connections[$key]['cfg']->mailbox_id);

        $connector = $this->connector;
        $connector($url)->then(function (WebSocket $conn) use ($key) {
            $this->onOpen($key, $conn);
        }, function ($e) use ($key) {
            $this->onFailure($key, $e instanceof \Throwable ? $e->getMessage() : 'connection failed');
        });
    }

    protected function onOpen($key, WebSocket $conn)
    {
        if ($this->stopping || !isset($this->connections[$key])) {
            $conn->close();

            return;
        }
        $state = &$this->connections[$key];
        $state['conn'] = $conn;
        $state['backoff'] = 0;
        $state['authed'] = false;
        $state['challenge'] = null;
        $state['auth_event_id'] = null;
        $this->log('connected to '.$state['url']);

        $conn->on('message', function (MessageInterface $msg) use ($key) {
            $this->onMessage($key, (string) $msg);
        });
        $conn->on('close', function ($code = null, $reason = null) use ($key) {
            $this->onClose($key, $code, $reason);
        });
        $conn->on('error', function ($e) use ($key) {
            $this->log('error on '.($this->connections[$key]['url'] ?? $key).': '.($e instanceof \Throwable ? $e->getMessage() : (string) $e));
        });

        $this->subscribe($key);
    }

    protected function subscribe($key)
    {
        $state = &$this->connections[$key];
        if (!$state['conn']) {
            return;
        }
        if ($state['sub']) {
            $state['conn']->send(RelayClient::encode(['CLOSE', $state['sub']]));
        }
        $state['sub'] = 'fs'.bin2hex(random_bytes(6));
        $state['conn']->send(RelayClient::encode(['REQ', $state['sub'], $this->filter($state['cfg'])]));
    }

    protected function onMessage($key, $payload)
    {
        if (!isset($this->connections[$key])) {
            return;
        }
        $state = &$this->connections[$key];
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return;
        }

        switch ($data[0] ?? '') {
            case 'EVENT':
                if (($data[1] ?? '') !== $state['sub']) {
                    break;
                }
                $event = EventBuilder::fromJson($data[2] ?? null);
                if ($event && (int) $event['kind'] === GiftWrap::KIND_WRAP) {
                    $this->handleEvent($key, $event);
                }
                break;

            case 'EOSE':
                $this->log('caught up with '.$state['url']);
                break;

            case 'AUTH':
                $state['challenge'] = (string) ($data[1] ?? '');
                if (!$state['authed']) {
                    $this->auth($key);
                }
                break;

            case 'OK':
                if ($state['auth_event_id'] && ($data[1] ?? '') === $state['auth_event_id']) {
                    if (!empty($data[2])) {
                        $state['authed'] = true;
                        $this->log('authenticated with '.$state['url']);
                        $this->subscribe($key);
                    } else {
                        $this->log('authentication rejected by '.$state['url'].': '.($data[3] ?? ''));
                    }
                }
                break;

            case 'CLOSED':
                if (($data[1] ?? '') !== $state['sub']) {
                    break;
                }
                $message = (string) ($data[2] ?? '');
                $this->log('subscription closed by '.$state['url'].': '.$message);
                if (RelayClient::isAuthRequired($message)) {
                    if ($state['authed']) {
                        $this->subscribe($key);
                    } elseif ($state['challenge'] !== null) {
                        $this->auth($key);
                    }
                }
                break;

            case 'NOTICE':
                $this->log('notice from '.$state['url'].': '.($data[1] ?? ''));
                break;
        }
    }

    protected function auth($key)
    {
        $state = &$this->connections[$key];
        if (!$state['conn'] || $state['challenge'] === null) {
            return;
        }
        try {
            $event = EventBuilder::finalize([
                'kind' => 22242,
                'tags' => [['relay', $state['url']], ['challenge', $state['challenge']]],
                'content' => '',
            ], $state['cfg']->getPrivateKey());
            $state['auth_event_id'] = $event['id'];
            $state['conn']->send(RelayClient::encode(['AUTH', $event]));
        } catch (\Throwable $e) {
            $this->log('could not authenticate with '.$state['url'].': '.$e->getMessage());
        }
    }

    protected function handleEvent($key, array $event)
    {
        $state = $this->connections[$key];
        try {
            $this->withDb(function () use ($state, $event) {
                $this->handler->handleGiftWrap($state['cfg'], $event, $state['url']);
            });
        } catch (\Throwable $e) {
            $this->log('error handling event '.substr($event['id'] ?? '', 0, 8).': '.$e->getMessage());
            \Log::error('[Nostr] '.$e->getMessage(), ['exception' => $e]);
        }
    }

    protected function onClose($key, $code, $reason)
    {
        if (!isset($this->connections[$key])) {
            return;
        }
        $this->connections[$key]['conn'] = null;
        $this->connections[$key]['sub'] = null;
        $this->log('connection to '.$this->connections[$key]['url'].' closed ('.$code.' '.$reason.')');
        $this->scheduleReconnect($key);
    }

    protected function onFailure($key, $message)
    {
        if (!isset($this->connections[$key])) {
            return;
        }
        $this->log('could not connect to '.$this->connections[$key]['url'].': '.$message);
        $this->scheduleReconnect($key);
    }

    protected function scheduleReconnect($key)
    {
        if ($this->stopping || !isset($this->connections[$key])) {
            return;
        }
        $state = &$this->connections[$key];
        $state['backoff'] = $state['backoff'] ? min(self::MAX_BACKOFF, $state['backoff'] * 2) : self::MIN_BACKOFF;
        $this->log('reconnecting to '.$state['url'].' in '.$state['backoff'].'s');
        $state['timer'] = $this->loop->addTimer($state['backoff'], function () use ($key) {
            if (isset($this->connections[$key])) {
                $this->connections[$key]['timer'] = null;
                $this->connect($key);
            }
        });
    }

    protected function drop($key)
    {
        if (!isset($this->connections[$key])) {
            return;
        }
        $state = $this->connections[$key];
        unset($this->connections[$key]);
        if ($state['timer']) {
            $this->loop->cancelTimer($state['timer']);
        }
        if ($state['conn']) {
            try {
                $state['conn']->close();
            } catch (\Throwable $e) {
                // Ignore.
            }
        }
    }

    protected function tick()
    {
        if ($this->stopping) {
            return;
        }
        if (time() - $this->startedAt >= $this->lifetime) {
            $this->log('lifetime reached, exiting');
            $this->stop();

            return;
        }

        try {
            $fingerprint = $this->withDb(function () {
                return $this->currentFingerprint();
            });
            if ($fingerprint !== $this->fingerprint) {
                $this->log('settings changed, updating subscriptions');
                $this->withDb(function () {
                    $this->reload();
                });
            }
        } catch (\Throwable $e) {
            $this->log('could not check settings: '.$e->getMessage());
        }

        foreach ($this->connections as $state) {
            if ($state['conn']) {
                try {
                    $state['conn']->send(new Frame('', true, Frame::OP_PING));
                } catch (\Throwable $e) {
                    // The close handler takes care of it.
                }
            }
        }
    }

    /**
     * Run database work, reconnecting once if the connection went away.
     */
    protected function withDb(callable $fn)
    {
        try {
            return $fn();
        } catch (\Illuminate\Database\QueryException $e) {
            if (!$this->isLostConnection($e)) {
                throw $e;
            }
            $this->log('database connection lost, reconnecting');
            \DB::reconnect();

            return $fn();
        }
    }

    protected function isLostConnection(\Throwable $e)
    {
        $message = $e->getMessage();
        foreach (['server has gone away', 'Lost connection', 'is dead or not enabled', 'no connection to the server', 'Connection refused', 'Broken pipe'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
