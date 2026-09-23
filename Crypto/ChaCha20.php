<?php

namespace Modules\Nostr\Crypto;

/**
 * ChaCha20 (RFC 8439) with a 96-bit nonce, as used by NIP-44.
 * Uses OpenSSL when it offers the cipher, otherwise a pure PHP implementation.
 */
class ChaCha20
{
    protected static $useOpenssl = null;

    /**
     * Encrypt or decrypt (the operation is symmetric).
     *
     * @param string $key   32 bytes
     * @param string $nonce 12 bytes
     * @param string $data
     * @param int    $counter initial block counter
     */
    public static function crypt($key, $nonce, $data, $counter = 0)
    {
        if (strlen($key) !== 32 || strlen($nonce) !== 12) {
            throw new \InvalidArgumentException('ChaCha20 needs a 32 byte key and a 12 byte nonce');
        }
        if ($data === '') {
            return '';
        }

        if (self::opensslAvailable()) {
            $result = openssl_encrypt($data, 'chacha20', $key, OPENSSL_RAW_DATA, pack('V', $counter).$nonce);
            if ($result !== false) {
                return $result;
            }
        }

        return self::cryptPure($key, $nonce, $data, $counter);
    }

    public static function opensslAvailable()
    {
        if (self::$useOpenssl === null) {
            self::$useOpenssl = function_exists('openssl_encrypt') && in_array('chacha20', openssl_get_cipher_methods(), true);
        }

        return self::$useOpenssl;
    }

    public static function forcePure($pure = true)
    {
        self::$useOpenssl = !$pure;
    }

    public static function cryptPure($key, $nonce, $data, $counter = 0)
    {
        $state = array_merge(
            [0x61707865, 0x3320646e, 0x79622d32, 0x6b206574],
            array_values(unpack('V8', $key)),
            [$counter & 0xffffffff],
            array_values(unpack('V3', $nonce))
        );

        $out = '';
        $len = strlen($data);
        for ($offset = 0; $offset < $len; $offset += 64) {
            $block = self::block($state);
            $out .= substr($data, $offset, 64) ^ $block;
            $state[12] = ($state[12] + 1) & 0xffffffff;
        }

        return $out;
    }

    protected static function block(array $s)
    {
        $x = $s;
        for ($i = 0; $i < 10; $i++) {
            self::quarterRound($x, 0, 4, 8, 12);
            self::quarterRound($x, 1, 5, 9, 13);
            self::quarterRound($x, 2, 6, 10, 14);
            self::quarterRound($x, 3, 7, 11, 15);
            self::quarterRound($x, 0, 5, 10, 15);
            self::quarterRound($x, 1, 6, 11, 12);
            self::quarterRound($x, 2, 7, 8, 13);
            self::quarterRound($x, 3, 4, 9, 14);
        }
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= pack('V', ($x[$i] + $s[$i]) & 0xffffffff);
        }

        return $out;
    }

    protected static function quarterRound(array &$x, $a, $b, $c, $d)
    {
        $x[$a] = ($x[$a] + $x[$b]) & 0xffffffff;
        $x[$d] = self::rotl($x[$d] ^ $x[$a], 16);
        $x[$c] = ($x[$c] + $x[$d]) & 0xffffffff;
        $x[$b] = self::rotl($x[$b] ^ $x[$c], 12);
        $x[$a] = ($x[$a] + $x[$b]) & 0xffffffff;
        $x[$d] = self::rotl($x[$d] ^ $x[$a], 8);
        $x[$c] = ($x[$c] + $x[$d]) & 0xffffffff;
        $x[$b] = self::rotl($x[$b] ^ $x[$c], 7);
    }

    protected static function rotl($v, $n)
    {
        return (($v << $n) | ($v >> (32 - $n))) & 0xffffffff;
    }
}
