<?php

namespace Modules\Nostr\Services;

use App\Thread;

class MessageSource
{
    public static function describe(Thread $thread): string
    {
        $tags = json_decode($thread->getHeader('Nostr-Tags') ?: '[]', true);
        if (!is_array($tags)) {
            return '';
        }
        $client = self::tag($tags, 'vpx_client', 5);
        $daemon = self::tag($tags, 'vpx_daemon', 4);
        $labels = ['desktop' => __('Desktop'), 'cli' => __('CLI'), 'web' => __('Web UI')];
        if ($client && !isset($labels[$client[2]])) {
            $client = null;
        }
        $source = '';
        if ($client) {
            $source = self::build($client[3], $client[4]);
            if ($client[2] !== 'desktop' || $source === '') {
                $source = $labels[$client[2]].($source !== '' ? ' · '.$source : '');
            }
        }
        if ($daemon && (!$client || $client[2] === 'web'
            || $client[3] !== $daemon[2] || $client[4] !== $daemon[3])) {
            $details = self::build($daemon[2], $daemon[3]);
            if ($details !== '') {
                $source .= ($source !== '' ? ' · ' : '').__('Daemon').': '.$details;
            }
        }

        return $source;
    }

    private static function build(string $platform, string $version): string
    {
        return implode(' · ', array_filter([$platform, $version !== '' ? '12VPX Neo '.$version : '']));
    }

    private static function tag(array $tags, string $name, int $length)
    {
        $matches = array_values(array_filter($tags, function ($tag) use ($name) {
            return is_array($tag) && ($tag[0] ?? null) === $name;
        }));
        if (count($matches) !== 1 || count($matches[0]) !== $length || ($matches[0][1] ?? null) !== '1') {
            return null;
        }
        $tag = $matches[0];
        for ($i = 2; $i < $length; $i++) {
            if (!isset($tag[$i]) || !is_string($tag[$i]) || strlen($tag[$i]) > 64
                || !preg_match('//u', $tag[$i]) || preg_match('/\p{Cc}/u', $tag[$i])) {
                return null;
            }
        }

        return $tag;
    }
}
