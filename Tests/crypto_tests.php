<?php
// Standalone checks for the crypto layer (no FreeScout bootstrap needed).
// Usage: php Tests/crypto_tests.php
require __DIR__.'/../vendor/autoload.php';

use Modules\Nostr\Crypto\Bech32;
use Modules\Nostr\Services\EventBuilder;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\Keys;
use swentel\nostr\Encryption\Nip44;

$fail = 0;
function check($name, $cond) { global $fail; if ($cond) { echo "ok   $name\n"; } else { echo "FAIL $name\n"; $fail++; } }

// NIP-19 examples from the NIP.
check('npub encode', Keys::npub('3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d') === 'npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6');
check('npub decode', Keys::toHex('npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6') === '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d');
check('nsec encode', Keys::nsec('67dea2ed018072d675f5415ecfaed7d2597555e202d85b3d65ea4e58d2d92ffa') === 'nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5');
check('nsec decode', Keys::toHex('nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5', 'priv') === '67dea2ed018072d675f5415ecfaed7d2597555e202d85b3d65ea4e58d2d92ffa');
check('nsec rejected as pub', Keys::toHex('nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5') === null);
check('nprofile decode', Keys::toHex('nprofile1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8gpp4mhxue69uhhytnc9e3k7mgpz4mhxue69uhkg6nzv9ejuumpv34kytnrdaksjlyr9p') === '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d');
check('hex pubkey accepted', Keys::toHex('3BF0C63FCB93463407AF97A5E5EE64FA883D107EF9E558472C4EB9AAAEFA459D') === '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d');
check('invalid x rejected', Keys::toHex(str_repeat('f', 64)) === null);
check('garbage rejected', Keys::toHex('hello') === null);

// Key generation and derivation.
$priv = Keys::generatePrivateKey();
$pub = Keys::pubkeyFromPrivate($priv);
check('generated key valid', Keys::isValidPrivateKey($priv) && Keys::isValidPubkey($pub));

// NIP-01 event id from the NIP-01 spec ("hello world" style check: sign + verify + tamper).
$event = EventBuilder::finalize(['kind' => 1, 'created_at' => 1700000000, 'tags' => [['t', 'test']], 'content' => "hello / world \"quoted\" \n ünïcode"], $priv);
check('event verifies', EventBuilder::verify($event));
$tampered = $event; $tampered['content'] .= '!';
check('tampered event rejected', !EventBuilder::verify($tampered));
check('serialization keeps slashes and unicode', strpos(EventBuilder::serialize($event), '"hello / world \"quoted\" \n ünïcode"') !== false);

// NIP-44 vectors (conversation key derivation uses elliptic-php, payload uses the library).
$v = json_decode(file_get_contents(__DIR__.'/vectors/nip44.json'), true)['v2']['valid'];
$ok = true;
foreach ($v['get_conversation_key'] as $case) {
    if (bin2hex(Nip44::getConversationKey($case['sec1'], $case['pub2'])) !== $case['conversation_key']) { $ok = false; echo "  conv key mismatch for {$case['pub2']}\n"; }
}
check('nip44 conversation keys ('.count($v['get_conversation_key']).')', $ok);
$ok = true;
foreach ($v['encrypt_decrypt'] as $case) {
    $key = hex2bin($case['conversation_key']);
    if (Nip44::encrypt($case['plaintext'], $key, hex2bin($case['nonce'])) !== $case['payload']) { $ok = false; echo "  encrypt mismatch\n"; }
    if (Nip44::decrypt($case['payload'], $key) !== $case['plaintext']) { $ok = false; echo "  decrypt mismatch\n"; }
}
check('nip44 encrypt/decrypt ('.count($v['encrypt_decrypt']).')', $ok);

// Gift wrap round trip.
$alicePriv = Keys::generatePrivateKey(); $alicePub = Keys::pubkeyFromPrivate($alicePriv);
$bobPriv = Keys::generatePrivateKey(); $bobPub = Keys::pubkeyFromPrivate($bobPriv);
[$wrap, $rumor] = GiftWrap::wrap(['kind' => 14, 'content' => 'Hi Bob, need help with my VPN 🙂', 'tags' => [['p', $bobPub], ['subject', 'VPN']]], $alicePriv, $bobPub);
check('wrap verifies', EventBuilder::verify($wrap) && $wrap['kind'] === 1059 && $wrap['pubkey'] !== $alicePub);
$out = GiftWrap::unwrap($wrap, $bobPriv, $bobPub);
check('unwrap content', $out['rumor']['content'] === 'Hi Bob, need help with my VPN 🙂');
check('unwrap author', $out['rumor']['pubkey'] === $alicePub && $out['seal']['pubkey'] === $alicePub);
check('unwrap id', $out['rumor']['id'] === $rumor['id'] && EventBuilder::id($out['rumor']) === $rumor['id']);
check('subject tag', EventBuilder::firstTag($out['rumor'], 'subject') === 'VPN');
$threw = false; try { GiftWrap::unwrap($wrap, $alicePriv, $alicePub); } catch (\Throwable $e) { $threw = true; }
check('unwrap by wrong key fails', $threw);
$bad = $wrap; $bad['content'] = substr($bad['content'], 0, -8).'AAAAAAAA';
$threw = false; try { GiftWrap::unwrap($bad, $bobPriv, $bobPub); } catch (\Throwable $e) { $threw = true; }
check('tampered wrap fails', $threw);

// Round trip through the nostr-php reference implementation for interoperability.
$ref = \swentel\nostr\Nip17\DirectMessage::decryptDirectMessage(json_decode(json_encode($wrap)), $bobPriv, true);
check('nostr-php can decrypt our wrap', is_array($ref) && $ref['content'] === 'Hi Bob, need help with my VPN 🙂');

echo $fail ? "$fail FAILED\n" : "ALL OK\n";
exit($fail ? 1 : 0);
