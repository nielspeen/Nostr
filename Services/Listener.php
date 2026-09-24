<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\Websocket\Client;

/**
 * Long-running relay listener: keeps one websocket per (mailbox, inbox relay),
 * subscribes to gift wraps addressed to the mailbox keys and hands them to the
 * IncomingMessageHandler. Reconnects with backoff, answers NIP-42 AUTH, picks up
 * settings changes, publishes a heartbeat and exits after its lifetime so the
 * scheduler restarts it. Plain stream_select(), no event loop library.
 */
class Listener
{
    const TICK = 30;
    const MIN_BACKOFF = 5;
    const MAX_BACKOFF = 300;
    // No bytes from the relay (not even a pong) for this long: reconnect.
    const IDLE_TIMEOUT = 120;

    /** @var IncomingMessageHandler */
    protected $handler;

    /** @var callable|null */
    protected $logger;

    /** @var int */
    protected $lifetime;

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

    /** @var resource|null */
    protected $lockHandle = null;

    /**
     * Stay connected until the lifetime is over or a signal arrives.
     *
     * @return bool false when another listener kept running instead
     */
    public function run()
    {
        if (!$this->acquireLock()) {
            return false;
        }
        $this->startedAt = time();
        $this->installSignalHandlers();
        $this->reload();
        $this->log('listener started (lifetime '.$this->lifetime.'s)');
        $this->publishStatus();

        $nextTick = time() + self::TICK;
        while (!$this->stopping) {
            $this->connectDue();

            $read = [];
            $write = [];
            foreach ($this->connections as $state) {
                if ($state['client']) {
                    $state['client']->selectSets($read, $write);
                }
            }
            if ($read || $write) {
                $except = null;
                @stream_select($read, $write, $except, 1);
            } else {
                usleep(500000);
            }

            foreach (array_keys($this->connections) as $key) {
                $this->service($key);
            }

            if (time() >= $nextTick) {
                $this->tick();
                $nextTick = time() + self::TICK;
            }
        }

        $this->log('listener stopped');
        $this->releaseLock();

        return true;
    }

    /**
     * Only one listener per installation. FreeScout clears its cache (and with it the
     * scheduler's mutex) when a module is updated, so a second listener can be started
     * while the old one still runs. The newcomer asks the old one to stop and takes over.
     */
    protected function acquireLock()
    {
        $this->stopOtherListeners();

        $path = storage_path('app/nostr-listen.lock');
        $this->lockHandle = @fopen($path, 'c+');
        if (!$this->lockHandle) {
            $this->log('cannot open '.$path.', running without the single instance lock');

            return true;
        }
        if ($this->tryLock()) {
            return true;
        }

        rewind($this->lockHandle);
        $otherPid = (int) trim((string) stream_get_contents($this->lockHandle));
        if ($otherPid > 0 && $otherPid !== getmypid() && function_exists('posix_kill')) {
            $this->log('another listener (pid '.$otherPid.') is running, asking it to stop');
            @posix_kill($otherPid, SIGTERM);
        } else {
            $this->log('another listener is running, waiting for it to stop');
        }

        $deadline = time() + 20;
        while (time() < $deadline) {
            usleep(500000);
            if ($this->tryLock()) {
                return true;
            }
        }
        $this->log('another listener is still running, exiting');
        fclose($this->lockHandle);
        $this->lockHandle = null;

        return false;
    }

    /**
     * Listeners from before the lock file existed do not know about it: ask every other
     * nostr:listen process on this machine to stop, whatever version it runs.
     */
    protected function stopOtherListeners()
    {
        if (!function_exists('posix_kill') || !function_exists('shell_exec')) {
            return;
        }
        try {
            $others = [];
            foreach (\Helper::getRunningProcesses('nostr:listen') as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && $pid !== getmypid() && $pid !== posix_getppid()) {
                    $others[] = $pid;
                }
            }
            if ($others) {
                $this->log('stopping other listener process(es): '.implode(', ', $others));
                foreach ($others as $pid) {
                    @posix_kill($pid, SIGTERM);
                }
                sleep(2);
            }
        } catch (\Throwable $e) {
            // Best effort only.
        }
    }

    protected function tryLock()
    {
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        ftruncate($this->lockHandle, 0);
        rewind($this->lockHandle);
        fwrite($this->lockHandle, (string) getmypid());
        fflush($this->lockHandle);

        return true;
    }

    protected function releaseLock()
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
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
                    if ((int) $event['kind'] === IncomingMessageHandler::KIND_LEGACY_DM) {
                        $this->handler->handleLegacyMessage($cfg, $event, null);
                    } elseif ($this->handler->handleGiftWrap($cfg, $event, null)) {
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

    public function stop($reason = 'signal')
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->publishStatus($reason);
        foreach (array_keys($this->connections) as $key) {
            $this->drop($key);
        }
    }

    protected function installSignalHandlers()
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal') || !defined('SIGTERM')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () {
                $this->log('signal received, stopping');
                $this->stop('signal');
            });
        }
    }

    protected function filter(NostrMailbox $cfg)
    {
        return [
            // Legacy NIP-04 messages (kind 4) are subscribed too, so that they can be reported.
            'kinds' => [GiftWrap::KIND_WRAP, IncomingMessageHandler::KIND_LEGACY_DM],
            '#p' => $cfg->getAllPubkeys(),
            'since' => time() - (int) config('nostr.lookback', 3 * 86400),
        ];
    }

    /**
     * Open or close connections to match the enabled mailboxes and their inbox relays.
     */
    protected function reload()
    {
        $wanted = [];
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
                    'client' => null,
                    'opened' => false,
                    'retry_at' => time(),
                    'backoff' => 0,
                    'sub' => null,
                    'challenge' => null,
                    'authed' => false,
                    'auth_event_id' => null,
                    'since' => null,
                    'caught_up' => false,
                    'events' => 0,
                    'last_event_at' => null,
                    'legacy' => 0,
                    'last_legacy_at' => null,
                    'error' => null,
                ];
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

    /**
     * Start connections whose retry time has come.
     */
    protected function connectDue()
    {
        if ($this->stopping) {
            return;
        }
        foreach ($this->connections as $key => $state) {
            if (!$state['client'] && $state['retry_at'] !== null && time() >= $state['retry_at']) {
                $this->connect($key);
            }
        }
    }

    protected function connect($key)
    {
        $state = &$this->connections[$key];
        $this->log('connecting to '.$state['url'].' for mailbox '.$state['cfg']->mailbox_id);
        $state['retry_at'] = null;
        $state['opened'] = false;
        $state['sub'] = null;

        try {
            $client = new Client($state['url']);
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage();
            $this->log('invalid relay '.$state['url'].': '.$e->getMessage());
            $state['retry_at'] = time() + self::MAX_BACKOFF;

            return;
        }
        $client->connect();
        $state['client'] = $client;
        // A refused or timed out connection shows up as a closed client in service().
    }

    /**
     * Drive one connection: state changes, incoming messages, failures.
     */
    protected function service($key)
    {
        if (!isset($this->connections[$key]) || !$this->connections[$key]['client']) {
            return;
        }
        $client = $this->connections[$key]['client'];
        $wasOpen = $this->connections[$key]['opened'];

        $client->tick();

        if ($client->isOpen() && !$wasOpen) {
            $this->onOpen($key);
        }
        foreach ($client->messages() as $payload) {
            if (!isset($this->connections[$key]) || $this->connections[$key]['client'] !== $client) {
                return;
            }
            $this->onMessage($key, $payload);
        }
        if (!isset($this->connections[$key]) || $this->connections[$key]['client'] !== $client) {
            return;
        }
        if ($client->isClosed()) {
            $this->onClose($key, $client->getError() ?: 'connection closed');
        } elseif ($client->isOpen() && time() - $client->getLastActivity() > self::IDLE_TIMEOUT) {
            $client->close();
            $this->onClose($key, 'no data for '.self::IDLE_TIMEOUT.'s');
        }
    }

    protected function onOpen($key)
    {
        $state = &$this->connections[$key];
        $state['opened'] = true;
        $state['backoff'] = 0;
        $state['authed'] = false;
        $state['challenge'] = null;
        $state['auth_event_id'] = null;
        $state['since'] = time();
        $state['caught_up'] = false;
        $state['error'] = null;
        $this->log('connected to '.$state['url']);
        $this->subscribe($key);
        $this->publishStatus();
    }

    protected function subscribe($key)
    {
        $state = &$this->connections[$key];
        if (!$state['client'] || !$state['client']->isOpen()) {
            return;
        }
        try {
            if ($state['sub']) {
                $state['client']->send(RelayClient::encode(['CLOSE', $state['sub']]));
            }
            $state['sub'] = 'fs'.bin2hex(random_bytes(6));
            $state['client']->send(RelayClient::encode(['REQ', $state['sub'], $this->filter($state['cfg'])]));
        } catch (\Throwable $e) {
            $this->log('could not subscribe on '.$state['url'].': '.$e->getMessage());
        }
    }

    protected function onMessage($key, $payload)
    {
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
                } elseif ($event && (int) $event['kind'] === IncomingMessageHandler::KIND_LEGACY_DM) {
                    $this->handleLegacy($key, $event);
                }
                break;

            case 'EOSE':
                $this->log('caught up with '.$state['url']);
                if (!$state['caught_up']) {
                    $state['caught_up'] = true;
                    $this->publishStatus();
                }
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
        if (!$state['client'] || !$state['client']->isOpen() || $state['challenge'] === null) {
            return;
        }
        try {
            $event = EventBuilder::finalize([
                'kind' => 22242,
                'tags' => [['relay', $state['url']], ['challenge', $state['challenge']]],
                'content' => '',
            ], $state['cfg']->getPrivateKey());
            $state['auth_event_id'] = $event['id'];
            $state['client']->send(RelayClient::encode(['AUTH', $event]));
        } catch (\Throwable $e) {
            $this->log('could not authenticate with '.$state['url'].': '.$e->getMessage());
        }
    }

    protected function handleEvent($key, array $event)
    {
        $state = $this->connections[$key];
        $this->connections[$key]['events']++;
        $this->connections[$key]['last_event_at'] = time();
        try {
            $this->withDb(function () use ($state, $event) {
                $this->handler->handleGiftWrap($state['cfg'], $event, $state['url']);
            });
        } catch (\Throwable $e) {
            $this->log('error handling event '.substr($event['id'] ?? '', 0, 8).': '.$e->getMessage());
            \Log::error('[Nostr] '.$e->getMessage(), ['exception' => $e]);
        }
        $this->publishStatus();
    }

    protected function handleLegacy($key, array $event)
    {
        $state = $this->connections[$key];
        try {
            $recorded = $this->withDb(function () use ($state, $event) {
                return $this->handler->handleLegacyMessage($state['cfg'], $event, $state['url']);
            });
            if ($recorded) {
                $this->connections[$key]['legacy']++;
                $this->connections[$key]['last_legacy_at'] = time();
                $this->publishStatus();
            }
        } catch (\Throwable $e) {
            $this->log('error recording legacy message: '.$e->getMessage());
        }
    }

    protected function onClose($key, $error)
    {
        if (!isset($this->connections[$key])) {
            return;
        }
        $state = &$this->connections[$key];
        $state['client'] = null;
        $state['opened'] = false;
        $state['sub'] = null;
        $state['error'] = mb_substr((string) $error, 0, 300);
        $this->log('connection to '.$state['url'].' lost: '.$error);
        $this->scheduleReconnect($key);
    }

    protected function scheduleReconnect($key)
    {
        if ($this->stopping || !isset($this->connections[$key])) {
            return;
        }
        $state = &$this->connections[$key];
        $state['backoff'] = $state['backoff'] ? min(self::MAX_BACKOFF, $state['backoff'] * 2) : self::MIN_BACKOFF;
        $state['retry_at'] = time() + $state['backoff'];
        $this->log('reconnecting to '.$state['url'].' in '.$state['backoff'].'s');
        $this->publishStatus();
    }

    protected function drop($key)
    {
        if (!isset($this->connections[$key])) {
            return;
        }
        $state = $this->connections[$key];
        unset($this->connections[$key]);
        if ($state['client']) {
            try {
                $state['client']->close();
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
            $this->stop('lifetime');

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
            if ($state['client'] && $state['client']->isOpen()) {
                try {
                    $state['client']->ping();
                } catch (\Throwable $e) {
                    // service() picks up the failure.
                }
            }
        }

        $this->publishStatus();
    }

    /**
     * Heartbeat for the settings page: process info and the state of every connection.
     */
    protected function publishStatus($stopReason = null)
    {
        $connections = [];
        foreach ($this->connections as $state) {
            if ($state['client']) {
                $connectionState = $state['client']->isOpen() ? 'connected' : 'connecting';
            } else {
                $connectionState = $state['retry_at'] !== null ? 'reconnecting' : 'connecting';
            }
            $connections[] = [
                'mailbox_id' => $state['cfg']->mailbox_id,
                'url' => $state['url'],
                'state' => $connectionState,
                'since' => $state['since'],
                'caught_up' => $state['caught_up'],
                'authed' => $state['authed'],
                'events' => $state['events'],
                'last_event_at' => $state['last_event_at'],
                'legacy' => $state['legacy'],
                'last_legacy_at' => $state['last_legacy_at'],
                'error' => $state['error'],
                'retry_in' => $connectionState === 'reconnecting' ? max(0, $state['retry_at'] - time()) : null,
            ];
        }
        try {
            ListenerStatus::write([
                'pid' => getmypid(),
                'host' => gethostname(),
                'started_at' => $this->startedAt,
                'lifetime' => $this->lifetime,
                'ends_at' => $this->startedAt + $this->lifetime,
                'stopped_at' => $stopReason ? time() : null,
                'stop_reason' => $stopReason,
                'connections' => $connections,
            ]);
        } catch (\Throwable $e) {
            $this->log('could not write status: '.$e->getMessage());
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
