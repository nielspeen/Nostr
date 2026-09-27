<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\Announcement;
use Modules\Nostr\Entities\NostrMailbox;

class AnnouncementPublisher
{
    public function build(array $input, $identifier, $privateKey, array $previous = [])
    {
        $title = trim((string) ($input['title'] ?? ''));
        $summary = trim((string) ($input['summary'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if (!$title || strlen($title) > 300 || strlen($summary) > 1000 || !$body || strlen($body) > 16384) {
            throw new \InvalidArgumentException('A title and message are required (maximum 300, 1000 and 16384 bytes for title, summary and message).');
        }
        $now = max(time(), ($previous['created_at'] ?? 0) + 1);
        $tags = [
            ['d', $identifier], ['t', 'vpx-announcement'], ['title', $title], ['summary', $summary],
            ['published_at', EventBuilder::firstTag($previous, 'published_at') ?: (string) $now],
        ];
        if (!empty($input['incident'])) {
            $tags[] = ['vpx-incident', 'true'];
        }
        if (!empty($input['expires_at'])) {
            $expires = strtotime($input['expires_at'].' UTC');
            if (!$expires || $expires <= time()) {
                throw new \InvalidArgumentException('Expiry must be a future date and time in UTC.');
            }
            $tags[] = ['expiration', (string) $expires];
        }
        return EventBuilder::finalize(['kind' => 30023, 'created_at' => $now, 'tags' => $tags, 'content' => $body], $privateKey);
    }

    public function publish(Announcement $announcement, NostrMailbox $cfg)
    {
        $privateKey = $cfg->getPrivateKeyFor($announcement->event['pubkey']);
        if (!$privateKey) {
            throw new \RuntimeException('The signing key is no longer available.');
        }
        // Persist the exact signed revision before any network request. Retrying
        // never creates a second announcement or changes its timestamp.
        $announcement->save();
        $announcement->relay_results = $this->deliver($announcement->event, $cfg->getAllRelays(), $privateKey);
        $announcement->save();
        return $announcement->relay_results;
    }

    protected function deliver(array $event, array $relays, $privateKey)
    {
        return (new RelayClient(RelayClient::authSignerForKey($privateKey)))->publish($event, $relays);
    }
}
