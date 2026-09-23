<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\NostrMailbox;

/**
 * Looks up other people's kind 0 profiles and kind 10050 DM relay lists.
 */
class RelayDiscovery
{
    const KIND_PROFILE = 0;
    const KIND_DM_RELAYS = 10050;
    const MAX_DM_RELAYS = 5;

    /** @var RelayClient */
    protected $client;

    public function __construct(?RelayClient $client = null)
    {
        $this->client = $client ?: new RelayClient();
    }

    /**
     * Relays where a pubkey wants to receive DMs (NIP-17 kind 10050).
     */
    public function dmRelays($pubkey, array $searchRelays)
    {
        $events = $this->client->fetch([[
            'kinds' => [self::KIND_DM_RELAYS],
            'authors' => [strtolower($pubkey)],
            'limit' => 1,
        ]], $searchRelays);

        if (!$events) {
            return [];
        }
        $relays = NostrMailbox::normalizeRelays(EventBuilder::tagValues($events[0], 'relay'));

        return array_slice($relays, 0, self::MAX_DM_RELAYS);
    }

    /**
     * Kind 0 profile as an array (name, display_name, picture, about, nip05...), or null.
     */
    public function profile($pubkey, array $searchRelays)
    {
        $events = $this->client->fetch([[
            'kinds' => [self::KIND_PROFILE],
            'authors' => [strtolower($pubkey)],
            'limit' => 1,
        ]], $searchRelays);

        if (!$events) {
            return null;
        }
        $profile = json_decode($events[0]['content'], true);
        if (!is_array($profile)) {
            return null;
        }
        $clean = [];
        foreach (['name', 'display_name', 'picture', 'about', 'nip05', 'website', 'lud16'] as $field) {
            if (isset($profile[$field]) && is_scalar($profile[$field])) {
                $clean[$field] = trim((string) $profile[$field]);
            }
        }
        $clean['created_at'] = (int) $events[0]['created_at'];

        return $clean;
    }
}
