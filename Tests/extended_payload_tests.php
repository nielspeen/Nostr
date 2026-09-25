<?php

require __DIR__.'/../vendor/autoload.php';

use Modules\Nostr\Crypto\Nip44;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\Keys;

$key = str_repeat("\x01", 32);
foreach ([65535, 65536, 65537, 100000, 1024 * 1024] as $length) {
    $text = str_repeat('x', $length);
    if (Nip44::decrypt(Nip44::encrypt($text, $key), $key) !== $text) {
        throw new RuntimeException('Extended payload round trip failed');
    }
}

class PaddingProbe extends Nip44
{
    public static function decode($value) { return parent::unpad($value); }
}
foreach (["\0\0", pack('nN', 0, 32).str_repeat('x', 32), pack('nN', 0, 65536).'short'] as $bad) {
    try {
        PaddingProbe::decode($bad);
        throw new LogicException('Invalid extended prefix accepted');
    } catch (RuntimeException $expected) {
    }
}

$sender = str_pad('1', 64, '0', STR_PAD_LEFT);
$recipient = str_pad('2', 64, '0', STR_PAD_LEFT);
$pubkey = Keys::pubkeyFromPrivate($recipient);
$conversation = Nip44::conversationKey($sender, $pubkey);
// The same compact vectors are checked by VPX's Rust NIP-44 implementation.
foreach ([
    65535 => '850f88b3b96b6502ad0a6739e73dd6b69da1e9532a4e98395330ca62cf9031c2',
    65536 => 'c5c0b735bc65602b0b1acb208e4ffa1eff74013d68269d26427727b8853684cb',
    100000 => 'abc580729fd50ea973b7bbbc9494416fdc1dcac7357a4fad75cd456287a97464',
] as $length => $hash) {
    $payload = Nip44::encrypt(str_repeat('x', $length), $conversation, str_repeat(chr(3), 32));
    if (hash('sha256', $payload) !== $hash) {
        throw new RuntimeException('Extended NIP-44 interoperability vector failed');
    }
}
[$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => str_repeat('x', 100000), 'tags' => [['p', $pubkey]]], $sender, $pubkey);
$opened = GiftWrap::unwrap($wrap, $recipient, $pubkey);
if ($opened['rumor']['content'] !== str_repeat('x', 100000)) {
    throw new RuntimeException('Large gift wrap failed');
}
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode($wrap, JSON_UNESCAPED_SLASHES)."\n");
}
echo "PASS: extended NIP-44 lengths, malformed prefixes, large gift wrap\n";
