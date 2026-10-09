<?php

namespace Modules\Nostr\Services;

use App\Attachment;
use App\Nostr\EventBuilder;
use App\Thread;

/** Files travel in encrypted rumor tags, never as externally hosted URLs. */
class InlineAttachments
{
    const TAG = 'vpx_attachment';
    const MAX_BYTES = 4 * 1024 * 1024;

    public static function forThread(Thread $thread): array
    {
        $tags = [];
        $total = 0;
        foreach ($thread->all_attachments as $attachment) {
            $name = $attachment->file_name;
            $mime = strtolower(trim(explode(';', $attachment->mime_type)[0]));
            if (!self::validName($name) || !preg_match('~\A[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+\z~', $mime)) {
                throw new \InvalidArgumentException(__('An attachment has an invalid filename or file type.'));
            }
            $remaining = self::MAX_BYTES - $total;
            if ($attachment->size > $remaining) {
                throw new \InvalidArgumentException(__('Attachments are too large to send inline.'));
            }
            $stream = \Storage::disk(Attachment::DISK)->readStream($attachment->getStorageFilePath());
            if (!$stream) {
                throw new \InvalidArgumentException(__('Could not read an attachment. Nothing was sent.'));
            }
            try {
                $data = stream_get_contents($stream, $remaining + 1);
            } finally {
                fclose($stream);
            }
            if ($data === false || strlen($data) > $remaining || !self::validContent($data, $mime)) {
                throw new \InvalidArgumentException(__('An attachment is invalid or too large to send inline.'));
            }
            $total += strlen($data);
            $tags[] = [self::TAG, '1', $name, $mime, base64_encode($data)];
        }

        // Remote/editor-only images have no local file to carry in the encrypted message.
        $html = preg_replace_callback('#<img\b[^>]*>#i', function ($match) use ($thread) {
            preg_match('/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $match[0], $source);
            $url = html_entity_decode(($source[1] ?? '') ?: (($source[2] ?? '') ?: ($source[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ($thread->all_attachments as $attachment) {
                if ($url === $attachment->url()) {
                    return '';
                }
            }
            throw new \InvalidArgumentException(__('Upload the image as an attachment to send it inline.'));
        }, (string) $thread->body);
        if (preg_match('#<(?:picture|audio|video|source|object|embed|iframe|svg|canvas)\b#i', $html)) {
            throw new \InvalidArgumentException(__('This embedded content cannot be sent inline. Nothing was sent.'));
        }

        return $tags;
    }

    /**
     * Screenshots from the app. Customers' devices may only send images; any
     * invalid tag rejects them all rather than attaching part of a message.
     */
    public static function extract(array $rumor, $maxBytes = self::MAX_BYTES): array
    {
        $attachments = [];
        $remaining = min(self::MAX_BYTES, $maxBytes);
        foreach (EventBuilder::tags($rumor, self::TAG) as $tag) {
            $data = count($tag) === 5 && $tag[1] === '1' && is_string($tag[4])
                && strlen($tag[4]) <= intdiv($remaining + 2, 3) * 4 ? base64_decode($tag[4], true) : false;
            if ($data === false || strlen($data) > $remaining || !is_string($tag[2]) || !self::validName($tag[2])
                || !in_array($tag[3], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
                || !self::validContent($data, $tag[3])) {
                throw new \InvalidArgumentException('Invalid or oversized encrypted image attachment');
            }
            $remaining -= strlen($data);
            $attachments[] = ['file_name' => $tag[2], 'mime_type' => $tag[3], 'data' => $tag[4]];
        }

        return $attachments;
    }

    public static function validName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 255 && $name !== '.' && $name !== '..'
            && !preg_match('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u', $name)
            && preg_match('//u', $name) && rtrim($name, '. ') === $name;
    }

    public static function validContent(string $data, string $mime): bool
    {
        if ($mime === 'text/plain') {
            return strpos($data, "\0") === false && preg_match('//u', $data);
        }
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return true;
        }
        $info = @getimagesizefromstring($data);

        return $info && $info['mime'] === $mime && $info[0] > 0 && $info[1] > 0
            && $info[0] * $info[1] <= 16000000;
    }

    public static function headerTags(array $tags): array
    {
        return array_values(array_filter($tags, function ($tag) { return ($tag[0] ?? '') !== self::TAG; }));
    }
}
