<?php

namespace Modules\Nostr\Services;

/** A text log carried entirely inside an authenticated, encrypted kind-14 rumor. */
class LogAttachment
{
    const TAG = 'vpx_log';
    const MAX_BYTES = 4 * 1024 * 1024;

    public static function extract(array $rumor, $maxBytes = self::MAX_BYTES)
    {
        $tags = EventBuilder::tags($rumor, self::TAG);
        if (!$tags) {
            return [];
        }
        $tag = $tags[0];
        if (count($tags) !== 1 || count($tag) !== 4 || $tag[1] !== '1'
            || !is_string($tag[2]) || !preg_match('/\Avpx-logs-[0-9]{8}-[0-9]{6}\.txt\z/', $tag[2])
            || !is_string($tag[3]) || $tag[3] === ''
            || strlen($tag[3]) > min(self::MAX_BYTES, $maxBytes)
            || strpos($tag[3], "\0") !== false || !preg_match('//u', $tag[3])) {
            throw new \InvalidArgumentException('Invalid or oversized encrypted log attachment');
        }

        return [[
            'file_name' => $tag[2],
            'mime_type' => 'text/plain',
            'data' => base64_encode($tag[3]),
        ]];
    }

    public static function headerTags(array $rumor)
    {
        // Keep the diagnostic payload out of both the body and Show original.
        return array_values(array_filter($rumor['tags'] ?? [], function ($tag) {
            return ($tag[0] ?? null) !== self::TAG;
        }));
    }
}
