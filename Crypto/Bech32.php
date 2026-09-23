<?php

namespace Modules\Nostr\Crypto;

/**
 * Bech32 (BIP-173) without the 90 character limit, as used by NIP-19.
 */
class Bech32
{
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    /**
     * @param string $hrp   human readable part (npub, nsec, nprofile...)
     * @param string $bytes binary data
     */
    public static function encode($hrp, $bytes)
    {
        $data = self::convertBits(array_values(unpack('C*', $bytes)), 8, 5, true);
        $checksum = self::createChecksum($hrp, $data);
        $out = $hrp.'1';
        foreach (array_merge($data, $checksum) as $d) {
            $out .= self::CHARSET[$d];
        }

        return $out;
    }

    /**
     * @return array [hrp, binary data]
     *
     * @throws \InvalidArgumentException
     */
    public static function decode($str)
    {
        $str = trim((string) $str);
        if ($str !== strtolower($str) && $str !== strtoupper($str)) {
            throw new \InvalidArgumentException('Mixed case bech32 string');
        }
        $str = strtolower($str);
        $pos = strrpos($str, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($str)) {
            throw new \InvalidArgumentException('Invalid bech32 string');
        }
        $hrp = substr($str, 0, $pos);
        $data = [];
        for ($i = $pos + 1; $i < strlen($str); $i++) {
            $d = strpos(self::CHARSET, $str[$i]);
            if ($d === false) {
                throw new \InvalidArgumentException('Invalid bech32 character');
            }
            $data[] = $d;
        }
        if (!self::verifyChecksum($hrp, $data)) {
            throw new \InvalidArgumentException('Invalid bech32 checksum');
        }
        $payload = array_slice($data, 0, -6);
        $bytes = self::convertBits($payload, 5, 8, false);

        return [$hrp, pack('C*', ...$bytes)];
    }

    /**
     * Parse NIP-19 TLV data into [type => [values...]].
     */
    public static function parseTlv($bytes)
    {
        $result = [];
        $i = 0;
        $len = strlen($bytes);
        while ($i + 2 <= $len) {
            $type = ord($bytes[$i]);
            $length = ord($bytes[$i + 1]);
            $value = substr($bytes, $i + 2, $length);
            if (strlen($value) !== $length) {
                break;
            }
            $result[$type][] = $value;
            $i += 2 + $length;
        }

        return $result;
    }

    public static function convertBits(array $data, $from, $to, $pad)
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $to) - 1;
        foreach ($data as $value) {
            if ($value < 0 || ($value >> $from) !== 0) {
                throw new \InvalidArgumentException('Invalid value for bit conversion');
            }
            $acc = ($acc << $from) | $value;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad) {
            if ($bits > 0) {
                $ret[] = ($acc << ($to - $bits)) & $maxv;
            }
        } elseif ($bits >= $from || (($acc << ($to - $bits)) & $maxv)) {
            throw new \InvalidArgumentException('Invalid padding in bit conversion');
        }

        return $ret;
    }

    protected static function polymod(array $values)
    {
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= self::GENERATOR[$i];
                }
            }
        }

        return $chk;
    }

    protected static function hrpExpand($hrp)
    {
        $ret = [];
        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $ret[] = ord($hrp[$i]) >> 5;
        }
        $ret[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $ret[] = ord($hrp[$i]) & 31;
        }

        return $ret;
    }

    protected static function verifyChecksum($hrp, array $data)
    {
        return self::polymod(array_merge(self::hrpExpand($hrp), $data)) === 1;
    }

    protected static function createChecksum($hrp, array $data)
    {
        $values = array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0]);
        $mod = self::polymod($values) ^ 1;
        $ret = [];
        for ($i = 0; $i < 6; $i++) {
            $ret[] = ($mod >> (5 * (5 - $i))) & 31;
        }

        return $ret;
    }
}
