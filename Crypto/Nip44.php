<?php

namespace Modules\Nostr\Crypto;

/**
 * NIP-44 v2 encrypted payloads (ECDH + HKDF + ChaCha20 + HMAC-SHA256).
 */
class Nip44
{
    const VERSION = 2;
    const MIN_PLAINTEXT = 1;
    // Bound allocations to the listener's existing 4 MiB message budget.
    const MAX_PLAINTEXT = 4 * 1024 * 1024;

    /**
     * 32 byte conversation key shared by both parties.
     */
    public static function conversationKey($privHex, $pubHex)
    {
        $sharedX = Schnorr::ecdhX($privHex, $pubHex);

        return hash_hmac('sha256', $sharedX, 'nip44-v2', true);
    }

    public static function encrypt($plaintext, $conversationKey, $nonce = null)
    {
        $nonce = $nonce === null ? random_bytes(32) : $nonce;
        if (strlen($nonce) !== 32) {
            throw new \InvalidArgumentException('Nonce must be 32 bytes');
        }
        list($chachaKey, $chachaNonce, $hmacKey) = self::messageKeys($conversationKey, $nonce);

        $padded = self::pad($plaintext);
        $ciphertext = ChaCha20::crypt($chachaKey, $chachaNonce, $padded);
        $mac = hash_hmac('sha256', $nonce.$ciphertext, $hmacKey, true);

        return base64_encode(chr(self::VERSION).$nonce.$ciphertext.$mac);
    }

    public static function decrypt($payload, $conversationKey)
    {
        $payload = (string) $payload;
        if ($payload === '' || $payload[0] === '#') {
            throw new \RuntimeException('Unsupported encryption version');
        }
        if (strlen($payload) > 4 * (int) ceil((self::MAX_PLAINTEXT + 71) / 3)) {
            throw new \RuntimeException('Invalid payload length');
        }
        $data = base64_decode($payload, true);
        if ($data === false) {
            throw new \RuntimeException('Invalid base64');
        }
        $length = strlen($data);
        if ($length < 99 || $length > self::MAX_PLAINTEXT + 71) {
            throw new \RuntimeException('Invalid payload length');
        }
        if (ord($data[0]) !== self::VERSION) {
            throw new \RuntimeException('Unknown encryption version '.ord($data[0]));
        }

        $nonce = substr($data, 1, 32);
        $ciphertext = substr($data, 33, $length - 65);
        $mac = substr($data, -32);

        list($chachaKey, $chachaNonce, $hmacKey) = self::messageKeys($conversationKey, $nonce);
        $expected = hash_hmac('sha256', $nonce.$ciphertext, $hmacKey, true);
        if (!hash_equals($expected, $mac)) {
            throw new \RuntimeException('Invalid MAC');
        }

        return self::unpad(ChaCha20::crypt($chachaKey, $chachaNonce, $ciphertext));
    }

    /**
     * HKDF-expand(conversation_key, nonce, 76) -> chacha key, chacha nonce, hmac key.
     */
    public static function messageKeys($conversationKey, $nonce)
    {
        if (strlen($conversationKey) !== 32) {
            throw new \InvalidArgumentException('Conversation key must be 32 bytes');
        }
        $okm = '';
        $previous = '';
        for ($i = 1; strlen($okm) < 76; $i++) {
            $previous = hash_hmac('sha256', $previous.$nonce.chr($i), $conversationKey, true);
            $okm .= $previous;
        }

        return [substr($okm, 0, 32), substr($okm, 32, 12), substr($okm, 44, 32)];
    }

    public static function calcPaddedLen($len)
    {
        if ($len <= 32) {
            return 32;
        }
        $nextPower = 1 << ((int) floor(log($len - 1, 2)) + 1);
        $chunk = $nextPower <= 256 ? 32 : intdiv($nextPower, 8);

        return $chunk * ((int) floor(($len - 1) / $chunk) + 1);
    }

    protected static function pad($plaintext)
    {
        $len = strlen($plaintext);
        if ($len < self::MIN_PLAINTEXT || $len > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('Invalid plaintext length');
        }

        $prefix = $len < 65536 ? pack('n', $len) : pack('nN', 0, $len);

        return $prefix.$plaintext.str_repeat("\0", self::calcPaddedLen($len) - $len);
    }

    protected static function unpad($padded)
    {
        if (strlen($padded) < 2) {
            throw new \RuntimeException('Invalid padding');
        }
        $len = unpack('n', substr($padded, 0, 2))[1];
        $prefix = 2;
        if ($len === 0) {
            if (strlen($padded) < 6) {
                throw new \RuntimeException('Invalid padding');
            }
            $len = unpack('N', substr($padded, 2, 4))[1];
            $prefix = 6;
            if ($len < 65536) {
                throw new \RuntimeException('Invalid extended length');
            }
        }
        if ($len < self::MIN_PLAINTEXT || $len > self::MAX_PLAINTEXT || strlen($padded) !== $prefix + self::calcPaddedLen($len)) {
            throw new \RuntimeException('Invalid padding');
        }

        return substr($padded, $prefix, $len);
    }
}
