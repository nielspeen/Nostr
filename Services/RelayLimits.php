<?php

namespace Modules\Nostr\Services;

use GuzzleHttp\Client;

class RelayLimits
{
    public function error(array $event, array $relays): ?string
    {
        $maxBytes = min(4 * 1024 * 1024, max(1, (int) \Option::get('nostr.max_message_bytes', 65536)));
        $maxContent = null;
        foreach (array_unique($relays) as $url) {
            $limits = $this->advertised($url);
            if (isset($limits['max_message_length'])) {
                $maxBytes = min($maxBytes, $limits['max_message_length']);
            }
            if (isset($limits['max_content_length'])) {
                $maxContent = min($maxContent ?? PHP_INT_MAX, $limits['max_content_length']);
            }
        }
        if (strlen(GiftWrap::encode(['EVENT', $event])) > $maxBytes) {
            return 'Encrypted message exceeds the relay limit of '.$maxBytes.' bytes';
        }
        if ($maxContent !== null && mb_strlen($event['content'] ?? '', 'UTF-8') > $maxContent) {
            return 'Encrypted message content exceeds the relay limit of '.$maxContent.' characters';
        }

        return null;
    }

    protected function advertised(string $relay): array
    {
        $key = 'nostr.relay_limits.'.hash('sha256', $relay);
        $cached = \Cache::get($key);
        if ($cached && $cached['retry_at'] > time()) {
            return $cached['limits'];
        }
        $limits = $cached['limits'] ?? [];
        $ttl = 600;
        try {
            $url = preg_replace('#^wss:#', 'https:', $relay);
            if (strpos($url, 'https://') !== 0) {
                return $limits;
            }
            $response = app(Client::class)->get($url, [
                'headers' => ['Accept' => 'application/nostr+json'],
                'timeout' => 8, 'read_timeout' => 8, 'allow_redirects' => false, 'stream' => true,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Relay information unavailable');
            }
            $body = $response->getBody();
            $text = '';
            while (!$body->eof()) {
                $text .= $body->read(8192);
                if (strlen($text) > 65536) {
                    throw new \RuntimeException('Relay information too large');
                }
            }
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            $limits = [];
            foreach (['max_message_length', 'max_content_length'] as $field) {
                $value = $data['limitation'][$field] ?? null;
                if (is_int($value) && $value > 0) {
                    $limits[$field] = $value;
                }
            }
            $ttl = 6 * 3600;
        } catch (\Exception $error) {
            // A failed refresh must not forget a previously smaller limit.
        }
        \Cache::put($key, ['limits' => $limits, 'retry_at' => time() + $ttl], now()->addDay());

        return $limits;
    }
}
