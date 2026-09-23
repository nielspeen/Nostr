<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\NostrMailbox;

/**
 * Publishes the mailbox's public metadata: kind 0 profile, kind 10050 DM relays
 * and kind 10002 relay list, so clients know who we are and where to send messages.
 */
class Announcer
{
    const KIND_PROFILE = 0;
    const KIND_RELAY_LIST = 10002;
    const KIND_DM_RELAYS = 10050;

    /** @var callable|null */
    protected $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @return array [kind => [relay => result]] or empty when nothing could be published
     */
    public function announce(NostrMailbox $cfg)
    {
        $priv = $cfg->getPrivateKey();
        if (!$priv || !$cfg->pubkey) {
            return [];
        }
        $inbox = $cfg->getInboxRelays();
        $targets = $cfg->getAllRelays();
        if (!$inbox || !$targets) {
            return [];
        }

        $client = new RelayClient(RelayClient::authSignerForKey($priv), $this->logger);
        $events = $this->buildEvents($cfg, $priv);

        $results = [];
        foreach ($events as $event) {
            $results[$event['kind']] = $client->publish($event, $targets);
        }

        $cfg->last_announced_at = now();
        $cfg->save();

        return $results;
    }

    /**
     * The signed events that describe this mailbox.
     */
    public function buildEvents(NostrMailbox $cfg, $priv)
    {
        $inbox = $cfg->getInboxRelays();
        $events = [];

        $events[] = EventBuilder::finalize([
            'kind' => self::KIND_DM_RELAYS,
            'tags' => array_map(function ($url) {
                return ['relay', $url];
            }, $inbox),
            'content' => '',
        ], $priv);

        $events[] = EventBuilder::finalize([
            'kind' => self::KIND_RELAY_LIST,
            'tags' => array_map(function ($url) {
                return ['r', $url];
            }, $inbox),
            'content' => '',
        ], $priv);

        $profile = $this->profileContent($cfg);
        if ($profile) {
            $events[] = EventBuilder::finalize([
                'kind' => self::KIND_PROFILE,
                'tags' => [],
                'content' => json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], $priv);
        }

        return $events;
    }

    public function profileContent(NostrMailbox $cfg)
    {
        $profile = [];
        if ($cfg->profile_name) {
            $profile['name'] = $cfg->profile_name;
            $profile['display_name'] = $cfg->profile_name;
        }
        if ($cfg->profile_about) {
            $profile['about'] = $cfg->profile_about;
        }
        if ($cfg->profile_picture) {
            $profile['picture'] = $cfg->profile_picture;
        }
        if ($cfg->getNip05()) {
            $profile['nip05'] = $cfg->getNip05();
        }

        return $profile;
    }
}
