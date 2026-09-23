<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Crypto\Bech32;
use Modules\Nostr\Crypto\Schnorr;

/**
 * Key generation and NIP-19 conversions.
 */
class Keys
{
    // secp256k1 group order.
    const N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    public static function generatePrivateKey()
    {
        do {
            $hex = bin2hex(random_bytes(32));
        } while (!self::isValidPrivateKey($hex));

        return $hex;
    }

    public static function isValidPrivateKey($hex)
    {
        $hex = strtolower((string) $hex);
        if (!EventBuilder::isHex($hex, 64)) {
            return false;
        }

        return $hex !== str_repeat('0', 64) && strcmp($hex, self::N) < 0;
    }

    public static function pubkeyFromPrivate($privHex)
    {
        return Schnorr::pubkey($privHex);
    }

    /**
     * Valid x-only public key (a point on the curve).
     */
    public static function isValidPubkey($hex)
    {
        $hex = strtolower((string) $hex);

        return EventBuilder::isHex($hex, 64) && Schnorr::liftX($hex) !== null;
    }

    public static function npub($pubHex)
    {
        return Bech32::encode('npub', hex2bin($pubHex));
    }

    public static function nsec($privHex)
    {
        return Bech32::encode('nsec', hex2bin($privHex));
    }

    public static function shortNpub($pubHex)
    {
        $npub = self::npub($pubHex);

        return substr($npub, 0, 12).'…'.substr($npub, -6);
    }

    /**
     * Accepts hex, npub, nprofile (or nsec for private keys) and returns hex, or null.
     *
     * @param string $input
     * @param string $expect 'pub' or 'priv'
     */
    public static function toHex($input, $expect = 'pub')
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        if (stripos($input, 'nostr:') === 0) {
            $input = substr($input, 6);
        }
        $input = trim($input);

        if (EventBuilder::isHex(strtolower($input), 64)) {
            $hex = strtolower($input);
        } else {
            try {
                [$hrp, $bytes] = Bech32::decode($input);
            } catch (\Throwable $e) {
                return null;
            }
            if ($hrp === 'nprofile') {
                $tlv = Bech32::parseTlv($bytes);
                if (empty($tlv[0][0]) || strlen($tlv[0][0]) !== 32) {
                    return null;
                }
                $hex = bin2hex($tlv[0][0]);
            } elseif (($hrp === 'npub' && $expect === 'pub') || ($hrp === 'nsec' && $expect === 'priv')) {
                if (strlen($bytes) !== 32) {
                    return null;
                }
                $hex = bin2hex($bytes);
            } else {
                return null;
            }
        }

        if ($expect === 'priv') {
            return self::isValidPrivateKey($hex) ? $hex : null;
        }

        return self::isValidPubkey($hex) ? $hex : null;
    }
}
