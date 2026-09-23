<?php

namespace Modules\Nostr\Crypto;

use BN\BN;
use Elliptic\EC;

/**
 * BIP-340 Schnorr signatures over secp256k1, as used by Nostr (NIP-01).
 *
 * Built on simplito/elliptic-php so it runs with either the gmp or the bcmath
 * extension (gmp is much faster and should be installed in production).
 */
class Schnorr
{
    /** @var EC|null */
    protected static $ec = null;

    protected static function ec()
    {
        if (self::$ec === null) {
            self::$ec = new EC('secp256k1');
        }

        return self::$ec;
    }

    /**
     * x-only public key (64 hex chars) for a private key (64 hex chars).
     */
    public static function pubkey($privHex)
    {
        $ec = self::ec();
        $d = new BN(self::hex($privHex), 16);
        if ($d->isZero() || $d->cmp($ec->n) >= 0) {
            throw new \InvalidArgumentException('Invalid private key');
        }
        $P = $ec->g->mul($d);

        return self::padHex($P->getX()->toString(16));
    }

    /**
     * Sign a message (hex, normally the 32-byte event id). Returns the 64-byte signature as hex.
     */
    public static function sign($msgHex, $privHex, $auxHex = null)
    {
        $ec = self::ec();
        $n = $ec->n;

        // BIP-340 allows any message length; Nostr always signs a 32-byte event id.
        $msg = hex2bin(self::hex($msgHex));

        $d0 = new BN(self::hex($privHex), 16);
        if ($d0->isZero() || $d0->cmp($n) >= 0) {
            throw new \InvalidArgumentException('Invalid private key');
        }

        $P = $ec->g->mul($d0);
        $d = $P->getY()->isEven() ? $d0 : $n->sub($d0);
        $pBytes = hex2bin(self::padHex($P->getX()->toString(16)));

        $aux = $auxHex !== null ? hex2bin(self::hex($auxHex)) : random_bytes(32);
        if (strlen($aux) !== 32) {
            throw new \InvalidArgumentException('Aux must be 32 bytes');
        }

        $t = hex2bin(self::padHex($d->toString(16))) ^ self::taggedHash('BIP0340/aux', $aux);
        $rand = self::taggedHash('BIP0340/nonce', $t.$pBytes.$msg);

        $k0 = (new BN(bin2hex($rand), 16))->umod($n);
        if ($k0->isZero()) {
            throw new \RuntimeException('Nonce is zero');
        }

        $R = $ec->g->mul($k0);
        $k = $R->getY()->isEven() ? $k0 : $n->sub($k0);
        $rBytes = hex2bin(self::padHex($R->getX()->toString(16)));

        $e = (new BN(bin2hex(self::taggedHash('BIP0340/challenge', $rBytes.$pBytes.$msg)), 16))->umod($n);
        $s = $k->add($e->mul($d))->umod($n);

        $sig = bin2hex($rBytes).self::padHex($s->toString(16));

        if (!self::verify($msgHex, bin2hex($pBytes), $sig)) {
            throw new \RuntimeException('Signature verification failed after signing');
        }

        return $sig;
    }

    /**
     * Verify a signature (hex) of a message (hex) for an x-only pubkey (hex).
     */
    public static function verify($msgHex, $pubHex, $sigHex)
    {
        try {
            $ec = self::ec();
            $n = $ec->n;
            $p = $ec->curve->p;

            $msg = hex2bin(self::hex($msgHex));
            $sig = hex2bin(self::hex($sigHex));
            $pub = hex2bin(self::hex($pubHex));
            if (strlen($sig) !== 64 || strlen($pub) !== 32) {
                return false;
            }

            $P = self::liftX(bin2hex($pub));
            if (!$P) {
                return false;
            }

            $r = new BN(bin2hex(substr($sig, 0, 32)), 16);
            $s = new BN(bin2hex(substr($sig, 32)), 16);
            if ($r->cmp($p) >= 0 || $s->cmp($n) >= 0) {
                return false;
            }

            $e = (new BN(bin2hex(self::taggedHash('BIP0340/challenge', substr($sig, 0, 32).$pub.$msg)), 16))->umod($n);

            // R = s*G - e*P
            $R = $ec->g->mul($s)->add($P->mul($e)->neg());
            if ($R->isInfinity()) {
                return false;
            }
            if (!$R->getY()->isEven()) {
                return false;
            }

            return $R->getX()->cmp($r) === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * x coordinate (32 raw bytes) of priv * P where P is the x-only public key: NIP-44's shared secret.
     */
    public static function ecdhX($privHex, $pubHex)
    {
        $ec = self::ec();
        $d = new BN(self::hex($privHex), 16);
        if ($d->isZero() || $d->cmp($ec->n) >= 0) {
            throw new \InvalidArgumentException('Invalid private key');
        }
        $P = self::liftX(self::hex($pubHex));
        if (!$P) {
            throw new \InvalidArgumentException('Invalid public key');
        }

        return hex2bin(self::padHex($P->mul($d)->getX()->toString(16)));
    }

    /**
     * Point with the given x coordinate and even y, or null if x is not on the curve.
     */
    public static function liftX($xHex)
    {
        $ec = self::ec();
        $x = new BN(self::hex($xHex), 16);
        if ($x->cmp($ec->curve->p) >= 0) {
            return null;
        }
        try {
            $P = $ec->curve->pointFromX($x, false);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$P->getY()->isEven()) {
            // Should not happen (pointFromX(x, odd=false) returns the even y), but be safe.
            $P = $ec->curve->point($P->getX(), $ec->curve->p->sub($P->getY()));
        }

        return $P;
    }

    public static function taggedHash($tag, $data)
    {
        $tagHash = hash('sha256', $tag, true);

        return hash('sha256', $tagHash.$tagHash.$data, true);
    }

    public static function padHex($hex, $len = 64)
    {
        return str_pad(strtolower($hex), $len, '0', STR_PAD_LEFT);
    }

    protected static function hex($hex)
    {
        $hex = strtolower(trim((string) $hex));
        if (!preg_match('/^[0-9a-f]*$/', $hex) || strlen($hex) % 2) {
            throw new \InvalidArgumentException('Invalid hex string');
        }

        return $hex;
    }
}
