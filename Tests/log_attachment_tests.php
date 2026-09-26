<?php

require __DIR__.'/../vendor/autoload.php';

use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\EventBuilder;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\LogAttachment;

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = json_decode(file_get_contents($argv[1] ?? __DIR__.'/vectors/vpx-log.json'), true);
$private = $fixture['recipient_private_test_key'];
$opened = GiftWrap::unwrap($fixture['wrap'], $private, Keys::pubkeyFromPrivate($private));
$rumor = $opened['rumor'];
$expected = $fixture['rumor'];
ksort($rumor);
ksort($expected);
check($rumor === $expected && EventBuilder::id($rumor) === $rumor['id'], 'Rust gift wrap did not decrypt to the expected rumor');
$attachments = LogAttachment::extract($rumor);
check(count($attachments) === 1 && $attachments[0]['mime_type'] === 'text/plain', 'Missing plain text attachment');
$data = base64_decode($attachments[0]['data'], true);
check(strpos($data, 'DNS timeout — 東京') !== false && strpos($data, 'Reconnected') !== false, 'Log text was changed');
check($attachments[0]['file_name'] === 'vpx-logs-20260926-123456.txt', 'Wrong filename');
check(strpos($rumor['content'], 'DNS timeout') === false && strlen($rumor['content']) < 200, 'Log leaked into message body');
check(strpos(json_encode(LogAttachment::headerTags($rumor)), 'DNS timeout') === false, 'Log leaked into headers');
check(LogAttachment::extract(['tags' => [['p', Keys::pubkeyFromPrivate($private)]]]) === [], 'Ordinary chat was changed');

$valid = ['vpx_log', '1', 'vpx-logs-20260926-123456.txt', 'test log'];
foreach ([
    [['vpx_log', '2', $valid[2], 'test']],
    [['vpx_log', '1', '../../escape.txt', 'test']],
    [['vpx_log', '1', 'vpx-logs-20260926-123456.html', '<script>']],
    [['vpx_log', '1', $valid[2], "bad\0text"]],
    [['vpx_log', '1', $valid[2], "\xff"]],
    [['vpx_log', '1', $valid[2], str_repeat('x', LogAttachment::MAX_BYTES + 1)]],
    [['vpx_log', '1', $valid[2], '']],
    [['vpx_log', '1']],
    [$valid, $valid],
] as $tags) {
    try {
        LogAttachment::extract(['tags' => $tags]);
        throw new RuntimeException('Invalid log attachment accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
try {
    LogAttachment::extract(['tags' => [$valid]], 3);
    throw new RuntimeException('Mailbox attachment limit ignored');
} catch (InvalidArgumentException $expected) {
}
echo "PASS: Rust encrypted log, attachment contents, short body, safe headers, malformed and oversized payloads\n";
