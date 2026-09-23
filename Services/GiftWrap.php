<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Crypto\Schnorr;
use Modules\Nostr\Crypto\Nip44;

/**
 * NIP-59 gift wrapping (rumor -> seal -> wrap) with NIP-44 encryption,
 * as required by NIP-17 private direct messages.
 */
class GiftWrap
{
    const KIND_DM = 14;
    const KIND_FILE = 15;
    const KIND_SEAL = 13;
    const KIND_WRAP = 1059;

    // Seals and wraps get timestamps randomized up to two days into the past.
    const MAX_TIME_SKEW = 2 * 86400;

    /**
     * Wrap an unsigned rumor for one recipient.
     *
     * @param array  $rumor        kind, content, tags (created_at optional)
     * @param string $senderPriv   sender's private key (hex)
     * @param string $recipientPub recipient's x-only public key (hex)
     *
     * @return array [wrap event, rumor with id]
     */
    public static function wrap(array $rumor, $senderPriv, $recipientPub)
    {
        $recipientPub = strtolower($recipientPub);
        $senderPub = Schnorr::pubkey($senderPriv);

        $rumor = EventBuilder::rumor($rumor, $senderPub);

        $sealKey = Nip44::conversationKey($senderPriv, $recipientPub);
        $seal = EventBuilder::finalize([
            'kind' => self::KIND_SEAL,
            'created_at' => self::randomPastTime(),
            'tags' => [],
            'content' => Nip44::encrypt(self::encode($rumor), $sealKey),
        ], $senderPriv);

        $ephemeralPriv = Keys::generatePrivateKey();
        $wrapKey = Nip44::conversationKey($ephemeralPriv, $recipientPub);
        $wrap = EventBuilder::finalize([
            'kind' => self::KIND_WRAP,
            'created_at' => self::randomPastTime(),
            'tags' => [['p', $recipientPub]],
            'content' => Nip44::encrypt(self::encode($seal), $wrapKey),
        ], $ephemeralPriv);

        return [$wrap, $rumor];
    }

    /**
     * Unwrap a kind 1059 event addressed to us.
     *
     * @return array ['rumor' => array, 'seal' => array]
     *
     * @throws \RuntimeException when the wrap is invalid or not for us
     */
    public static function unwrap(array $wrap, $recipientPriv, $recipientPub)
    {
        $recipientPub = strtolower($recipientPub);

        if ((int) ($wrap['kind'] ?? 0) !== self::KIND_WRAP) {
            throw new \RuntimeException('Not a gift wrap');
        }
        if (!EventBuilder::verify($wrap)) {
            throw new \RuntimeException('Invalid gift wrap signature');
        }
        if (!in_array($recipientPub, array_map('strtolower', EventBuilder::tagValues($wrap, 'p')))) {
            throw new \RuntimeException('Gift wrap is not addressed to this key');
        }

        $wrapKey = Nip44::conversationKey($recipientPriv, $wrap['pubkey']);
        $seal = self::decode(Nip44::decrypt($wrap['content'], $wrapKey));
        if (!$seal || (int) ($seal['kind'] ?? 0) !== self::KIND_SEAL) {
            throw new \RuntimeException('Invalid seal');
        }
        if (!EventBuilder::verify($seal)) {
            throw new \RuntimeException('Invalid seal signature');
        }

        $sealKey = Nip44::conversationKey($recipientPriv, $seal['pubkey']);
        $rumor = self::decode(Nip44::decrypt($seal['content'], $sealKey));
        if (!$rumor || !isset($rumor['kind'], $rumor['pubkey'], $rumor['content'])) {
            throw new \RuntimeException('Invalid rumor');
        }
        // The seal proves who wrote the rumor; the rumor must claim the same author.
        if (strtolower($rumor['pubkey']) !== strtolower($seal['pubkey'])) {
            throw new \RuntimeException('Rumor author does not match seal author');
        }
        if (!empty($rumor['sig'])) {
            throw new \RuntimeException('Rumor must not be signed');
        }
        $rumor['pubkey'] = strtolower($rumor['pubkey']);
        $rumor['tags'] = EventBuilder::normalizeTags($rumor['tags'] ?? []);
        $rumor['created_at'] = (int) ($rumor['created_at'] ?? 0);
        $rumor['kind'] = (int) $rumor['kind'];
        $rumor['content'] = (string) $rumor['content'];
        if (empty($rumor['id']) || !EventBuilder::isHex($rumor['id'], 64)) {
            $rumor['id'] = EventBuilder::id($rumor);
        }

        return ['rumor' => $rumor, 'seal' => $seal];
    }

    public static function randomPastTime()
    {
        return time() - random_int(0, self::MAX_TIME_SKEW);
    }

    public static function encode(array $event)
    {
        return json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decode($json)
    {
        $event = json_decode((string) $json, true);

        return is_array($event) ? $event : null;
    }
}
