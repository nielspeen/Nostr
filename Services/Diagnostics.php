<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\NostrMailbox;

/**
 * Asks relays what they hold for a mailbox: pending gift wraps, legacy messages,
 * and whether the mailbox's own announcements can be found. Used by the settings
 * page ("Check relays") and the nostr:diagnose command.
 */
class Diagnostics
{
    // Well known relays checked in addition to the mailbox's own, because senders may
    // have delivered there when they could not find our DM relay list.
    const EXTRA_RELAYS = ['wss://relay.damus.io', 'wss://relay.snort.social', 'wss://offchain.pub'];

    /** @var callable|null */
    protected $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @return array [
     *   'relays' => [url => ['ok' => bool, 'error' => ?string, 'wraps' => int, 'legacy' => int, 'unseen_wraps' => int, 'dm_relays' => ?array, 'profile' => bool]],
     *   'senders' => [pubkey => ['wraps' => int, 'legacy' => int]],
     * ]
     */
    public function run(NostrMailbox $cfg, $perRelayTimeout = 6)
    {
        $pubkeys = $cfg->getAllPubkeys();
        $relays = array_values(array_unique(array_merge($cfg->getInboxRelays(), $cfg->getAnnounceRelays(), self::EXTRA_RELAYS)));
        $since = time() - (int) config('nostr.lookback', 3 * 86400);
        $client = new RelayClient(RelayClient::authSignerForKey($cfg->getPrivateKey()), $this->logger);

        $result = ['relays' => [], 'senders' => []];
        foreach ($relays as $url) {
            $row = ['ok' => false, 'error' => null, 'wraps' => 0, 'legacy' => 0, 'unseen_wraps' => 0, 'dm_relays' => null, 'profile' => false, 'inbox' => in_array($url, $cfg->getInboxRelays())];
            $errors = [];
            $probe = new RelayClient(RelayClient::authSignerForKey($cfg->getPrivateKey()), function ($m) use (&$errors) {
                $errors[] = $m;
            });

            $events = $probe->fetch([
                ['kinds' => [GiftWrap::KIND_WRAP, IncomingMessageHandler::KIND_LEGACY_DM], '#p' => $pubkeys, 'since' => $since, 'limit' => 200],
            ], [$url], $perRelayTimeout, false);
            $announcements = $probe->fetch([
                ['kinds' => [0, 10050], 'authors' => [$cfg->pubkey], 'limit' => 2],
            ], [$url], $perRelayTimeout, true);

            $failed = array_filter($errors, function ($m) {
                return strpos($m, 'failed') !== false || strpos($m, 'closed by') !== false;
            });
            $row['ok'] = !$failed;
            $row['error'] = $failed ? mb_substr(preg_replace('/^.*?: /', '', end($failed)), 0, 200) : null;

            foreach ($events as $event) {
                $sender = $event['pubkey'] ?? '';
                if ((int) $event['kind'] === GiftWrap::KIND_WRAP) {
                    $row['wraps']++;
                    if (!\Modules\Nostr\Entities\NostrEvent::seenWrap($event['id'])) {
                        $row['unseen_wraps']++;
                    }
                } else {
                    $row['legacy']++;
                    $result['senders'][$sender] = $result['senders'][$sender] ?? ['wraps' => 0, 'legacy' => 0];
                    $result['senders'][$sender]['legacy']++;
                }
            }
            foreach ($announcements as $event) {
                if ((int) $event['kind'] === 0) {
                    $row['profile'] = true;
                } elseif ((int) $event['kind'] === 10050) {
                    $row['dm_relays'] = NostrMailbox::normalizeRelays(EventBuilder::tagValues($event, 'relay'));
                }
            }
            $result['relays'][$url] = $row;
            $this->log(sprintf('%s: %s, %d wrap(s), %d legacy, profile %s, dm relays %s', $url, $row['ok'] ? 'ok' : 'failed ('.$row['error'].')',
                $row['wraps'], $row['legacy'], $row['profile'] ? 'found' : 'missing', $row['dm_relays'] === null ? 'missing' : implode(' ', $row['dm_relays'])));
        }

        return $result;
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
