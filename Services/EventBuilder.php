<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Crypto\Schnorr;

/**
 * NIP-01 event serialization, ids, signatures and tag helpers.
 * Events are plain arrays: kind, pubkey, created_at, tags, content, id, sig.
 */
class EventBuilder
{
    public static function serialize(array $event)
    {
        return json_encode([
            0,
            (string) ($event['pubkey'] ?? ''),
            (int) ($event['created_at'] ?? 0),
            (int) ($event['kind'] ?? 0),
            self::normalizeTags($event['tags'] ?? []),
            (string) ($event['content'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function id(array $event)
    {
        return hash('sha256', self::serialize($event));
    }

    /**
     * Set pubkey, id and signature on an event.
     */
    public static function finalize(array $event, $privHex)
    {
        $event['pubkey'] = Schnorr::pubkey($privHex);
        $event['created_at'] = (int) ($event['created_at'] ?? time());
        $event['kind'] = (int) ($event['kind'] ?? 1);
        $event['tags'] = self::normalizeTags($event['tags'] ?? []);
        $event['content'] = (string) ($event['content'] ?? '');
        $event['id'] = self::id($event);
        $event['sig'] = Schnorr::sign($event['id'], $privHex);

        return $event;
    }

    /**
     * Unsigned event with a valid id (a NIP-59 "rumor").
     */
    public static function rumor(array $event, $pubkey)
    {
        $event['pubkey'] = strtolower($pubkey);
        $event['created_at'] = (int) ($event['created_at'] ?? time());
        $event['kind'] = (int) ($event['kind'] ?? 14);
        $event['tags'] = self::normalizeTags($event['tags'] ?? []);
        $event['content'] = (string) ($event['content'] ?? '');
        $event['id'] = self::id($event);
        unset($event['sig']);

        return $event;
    }

    /**
     * Structure, id and signature check.
     */
    public static function verify($event)
    {
        if (!is_array($event)) {
            return false;
        }
        foreach (['id', 'pubkey', 'sig', 'created_at', 'kind', 'tags', 'content'] as $field) {
            if (!array_key_exists($field, $event)) {
                return false;
            }
        }
        if (!self::isHex($event['id'], 64) || !self::isHex($event['pubkey'], 64) || !self::isHex($event['sig'], 128)) {
            return false;
        }
        if (!is_array($event['tags']) || !is_string($event['content'])) {
            return false;
        }
        if (!hash_equals(self::id($event), strtolower($event['id']))) {
            return false;
        }

        return Schnorr::verify($event['id'], $event['pubkey'], $event['sig']);
    }

    public static function isHex($value, $length)
    {
        return is_string($value) && strlen($value) === $length && ctype_xdigit($value);
    }

    /**
     * First value of the first tag with this name.
     */
    public static function firstTag(array $event, $name)
    {
        foreach ($event['tags'] ?? [] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name) {
                return isset($tag[1]) ? (string) $tag[1] : '';
            }
        }

        return null;
    }

    /**
     * First values of all tags with this name.
     */
    public static function tagValues(array $event, $name)
    {
        $values = [];
        foreach ($event['tags'] ?? [] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && isset($tag[1])) {
                $values[] = (string) $tag[1];
            }
        }

        return $values;
    }

    /**
     * Whole tags with this name.
     */
    public static function tags(array $event, $name)
    {
        $result = [];
        foreach ($event['tags'] ?? [] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name) {
                $result[] = $tag;
            }
        }

        return $result;
    }

    public static function normalizeTags($tags)
    {
        $result = [];
        foreach ((array) $tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $result[] = array_values(array_map('strval', $tag));
        }

        return $result;
    }

    /**
     * Decode a JSON event from a relay into an array, or null.
     */
    public static function fromJson($json)
    {
        $event = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($event) || !isset($event['kind'])) {
            return null;
        }
        if (is_object($event['tags'] ?? null)) {
            $event['tags'] = (array) $event['tags'];
        }

        return $event;
    }
}
