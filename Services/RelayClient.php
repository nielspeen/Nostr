<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Services\Websocket\Client;

/**
 * Synchronous relay client used from web requests and queue jobs:
 * publishes events and runs short REQ queries. Handles NIP-42 AUTH.
 */
class RelayClient
{
    /** @var callable|null function (string $relayUrl, string $challenge): array|null */
    protected $authSigner;

    /** @var callable|null function (string $message) */
    protected $logger;

    public function __construct(?callable $authSigner = null, ?callable $logger = null)
    {
        $this->authSigner = $authSigner;
        $this->logger = $logger;
    }

    /**
     * Signer for a mailbox key: answers relay AUTH challenges with a kind 22242 event.
     */
    public static function authSignerForKey($privHex)
    {
        return function ($relayUrl, $challenge) use ($privHex) {
            return EventBuilder::finalize([
                'kind' => 22242,
                'tags' => [['relay', $relayUrl], ['challenge', (string) $challenge]],
                'content' => '',
            ], $privHex);
        };
    }

    /**
     * Publish one signed event to several relays.
     *
     * @return array [relay url => ['ok' => bool, 'message' => string]]
     */
    public function publish(array $event, array $relays, $timeout = null)
    {
        $timeout = $timeout ?: config('nostr.publish_timeout', 10);
        $results = [];
        foreach (array_unique($relays) as $url) {
            $results[$url] = $this->publishToRelay($event, $url, $timeout);
            $this->log(sprintf('publish %s to %s: %s', substr($event['id'], 0, 8), $url,
                $results[$url]['ok'] ? 'ok' : 'failed ('.$results[$url]['message'].')'));
        }

        return $results;
    }

    public static function anySucceeded(array $results)
    {
        foreach ($results as $result) {
            if (!empty($result['ok'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Query several relays. Returns verified events, newest first, without duplicates.
     */
    public function fetch(array $filters, array $relays, $timeout = null, $verify = true)
    {
        $timeout = $timeout ?: config('nostr.fetch_timeout', 6);
        $events = [];
        foreach (array_unique($relays) as $url) {
            foreach ($this->fetchFromRelay($filters, $url, $timeout) as $event) {
                if (empty($event['id']) || isset($events[$event['id']])) {
                    continue;
                }
                if ($verify && !EventBuilder::verify($event)) {
                    continue;
                }
                $events[$event['id']] = $event;
            }
        }
        usort($events, function ($a, $b) {
            return ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0);
        });

        return array_values($events);
    }

    protected function publishToRelay(array $event, $url, $timeout)
    {
        $deadline = microtime(true) + $timeout;
        $client = null;
        $payload = self::encode(['EVENT', $event]);
        $challenge = null;
        $authed = false;

        try {
            $client = $this->connect($url, $timeout);
            $client->send($payload);

            while (($remaining = $deadline - microtime(true)) > 0) {
                $data = $this->receiveJson($client, min(1, $remaining));
                if ($data === null) {
                    continue;
                }
                $type = $data[0] ?? '';

                if ($type === 'AUTH') {
                    $challenge = (string) ($data[1] ?? '');
                    continue;
                }
                if ($type === 'OK' && ($data[1] ?? '') === $event['id']) {
                    $ok = (bool) ($data[2] ?? false);
                    $message = (string) ($data[3] ?? '');
                    if (!$ok && !$authed && self::isAuthRequired($message)
                        && $challenge !== null && $this->sendAuth($client, $url, $challenge)) {
                        $authed = true;
                        $client->send($payload);
                        continue;
                    }

                    return ['ok' => $ok, 'message' => $message];
                }
                if ($type === 'NOTICE') {
                    $this->log('notice from '.$url.': '.($data[1] ?? ''));
                }
            }

            return ['ok' => false, 'message' => 'timeout'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $this->close($client);
        }
    }

    protected function fetchFromRelay(array $filters, $url, $timeout)
    {
        $deadline = microtime(true) + $timeout;
        $client = null;
        $subId = bin2hex(random_bytes(8));
        $payload = self::encode(array_merge(['REQ', $subId], array_values($filters)));
        $events = [];
        $challenge = null;
        $authed = false;

        try {
            $client = $this->connect($url, $timeout);
            $client->send($payload);

            while (($remaining = $deadline - microtime(true)) > 0) {
                $data = $this->receiveJson($client, min(1, $remaining));
                if ($data === null) {
                    continue;
                }
                $type = $data[0] ?? '';

                if ($type === 'EVENT' && ($data[1] ?? '') === $subId) {
                    $event = EventBuilder::fromJson($data[2] ?? null);
                    if ($event) {
                        $events[] = $event;
                    }
                } elseif ($type === 'EOSE' && ($data[1] ?? '') === $subId) {
                    break;
                } elseif ($type === 'AUTH') {
                    $challenge = (string) ($data[1] ?? '');
                } elseif ($type === 'CLOSED' && ($data[1] ?? '') === $subId) {
                    $message = (string) ($data[2] ?? '');
                    if (!$authed && self::isAuthRequired($message)
                        && $challenge !== null && $this->sendAuth($client, $url, $challenge)) {
                        $authed = true;
                        $client->send($payload);
                        continue;
                    }
                    $this->log('subscription closed by '.$url.': '.$message);
                    break;
                } elseif ($type === 'NOTICE') {
                    $this->log('notice from '.$url.': '.($data[1] ?? ''));
                }
            }

            try {
                $client->send(self::encode(['CLOSE', $subId]));
            } catch (\Throwable $e) {
                // Ignore.
            }
        } catch (\Throwable $e) {
            $this->log('fetch from '.$url.' failed: '.$e->getMessage());
        } finally {
            $this->close($client);
        }

        return $events;
    }

    protected function sendAuth(Client $client, $url, $challenge)
    {
        if (!$this->authSigner) {
            return false;
        }
        try {
            $event = call_user_func($this->authSigner, $url, $challenge);
            if (!$event) {
                return false;
            }
            $client->send(self::encode(['AUTH', $event]));
            // Wait for the OK of the auth event (or give up quietly).
            $until = microtime(true) + 5;
            while (($remaining = $until - microtime(true)) > 0) {
                $data = $this->receiveJson($client, min(1, $remaining));
                if ($data && ($data[0] ?? '') === 'OK' && ($data[1] ?? '') === $event['id']) {
                    return (bool) ($data[2] ?? false);
                }
            }
        } catch (\Throwable $e) {
            $this->log('auth with '.$url.' failed: '.$e->getMessage());
        }

        return false;
    }

    protected function connect($url, $timeout)
    {
        $client = new Client($url);
        if (!$client->open(min(Client::CONNECT_TIMEOUT, max(1, $timeout)))) {
            throw new \RuntimeException($client->getError() ?: 'could not connect');
        }

        return $client;
    }

    /**
     * Next message decoded as JSON, or null when nothing usable arrived within $seconds.
     * Throws when the connection is closed.
     */
    protected function receiveJson(Client $client, $seconds)
    {
        $message = $client->receive($seconds);
        if ($message === null) {
            return null;
        }
        $data = json_decode($message, true);

        return is_array($data) ? $data : null;
    }

    protected function close($client)
    {
        if ($client) {
            try {
                $client->close();
            } catch (\Throwable $e) {
                // Ignore.
            }
        }
    }

    /**
     * NIP-42 machine readable prefix; some relays put "ERROR: " in front of it.
     */
    public static function isAuthRequired($message)
    {
        return stripos((string) $message, 'auth-required') !== false;
    }

    public static function encode($data)
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
