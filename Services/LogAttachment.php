<?php

namespace Modules\Nostr\Services;

use App\Nostr\EventBuilder;

/**
 * A text log carried entirely inside an authenticated, encrypted kind-14 rumor (a vpx_log tag),
 * from any app: valid UTF-8 text within the size limit, stored as text/plain under its own name.
 */
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
        // The reason goes to the log, so a rejected log can be traced to its app.
        $reason = null;
        if (count($tags) !== 1 || count($tag) !== 4 || $tag[1] !== '1') {
            $reason = 'unexpected vpx_log tag';
        } elseif (!is_string($tag[2]) || trim($tag[2]) === '' || mb_strlen($tag[2]) > 255) {
            // Any app's log, by any name: Tallport makes it safe when it stores it (Attachment::create()).
            $reason = 'missing or overlong file name';
        } elseif (!is_string($tag[3]) || $tag[3] === '') {
            $reason = 'empty log';
        } elseif (strlen($tag[3]) > min(self::MAX_BYTES, $maxBytes)) {
            $reason = 'log of '.strlen($tag[3]).' bytes is over the limit';
        } elseif (strpos($tag[3], "\0") !== false || !preg_match('//u', $tag[3])) {
            $reason = 'log is not valid UTF-8 text';
        }
        if ($reason) {
            throw new \InvalidArgumentException('Encrypted log attachment rejected: '.$reason);
        }

        return [[
            'file_name' => basename(str_replace('\\', '/', $tag[2])),
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
