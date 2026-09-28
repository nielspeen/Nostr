<?php

require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Thread;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\LogAttachment;
use Modules\Nostr\Services\MessageSource;

$failures = 0;
function sourceThread(array $tags): Thread
{
    $thread = new Thread();
    $thread->headers = 'Nostr-Tags: '.json_encode($tags, JSON_UNESCAPED_UNICODE);

    return $thread;
}

runCase('sender line distinguishes client and daemon and retains each message version', function () {
    $desktop = ['vpx_client', '1', 'desktop', 'Windows', '26.9.25'];
    $daemon = ['vpx_daemon', '1', 'Windows', '26.9.25'];
    $old = sourceThread([$desktop, $daemon]);
    check(MessageSource::describe($old) === 'Windows · 12VPX Neo 26.9.25', 'matching versions were duplicated');
    $daemon[2] = 'OpenWRT';
    $daemon[3] = '26.9.26';
    check(MessageSource::describe(sourceThread([$desktop, $daemon])) ===
        'Windows · 12VPX Neo 26.9.25 · Daemon: OpenWRT · 12VPX Neo 26.9.26', 'remote daemon was conflated with app');
    $desktop[4] = '26.9.26';
    $daemon[2] = 'Windows';
    check(MessageSource::describe(sourceThread([$desktop, $daemon])) === 'Windows · 12VPX Neo 26.9.26', 'new version missing');
    check(MessageSource::describe($old) === 'Windows · 12VPX Neo 26.9.25', 'historical version changed');
    $desktop[2] = 'cli';
    check(MessageSource::describe(sourceThread([$desktop, $daemon])) === 'CLI · Windows · 12VPX Neo 26.9.26', 'CLI not identified');
    check(MessageSource::describe(sourceThread([['vpx_client', '1', 'web', '', ''], $daemon])) ===
        'Web UI · Daemon: Windows · 12VPX Neo 26.9.26', 'browser borrowed daemon platform');
    check(MessageSource::describe(sourceThread([$daemon])) === 'Daemon: Windows · 12VPX Neo 26.9.26', 'API sender not identified');
});

runCase('missing or malformed metadata leaves the message usable', function () {
    check(MessageSource::describe(new Thread()) === '', 'old message gained metadata');
    $valid = ['vpx_client', '1', 'desktop', 'Windows', '26.9.25'];
    foreach ([
        [], [['p', str_repeat('a', 64)]], [$valid, $valid],
        [['vpx_client', '2', 'desktop', 'Windows', '26.9.25']],
        [['vpx_client', '1', 'unknown', 'Windows', '26.9.25']],
        [['vpx_client', '1', 'desktop', "Windows\nInjected", '26.9.25']],
        [['vpx_client', '1', 'desktop', 'Windows', str_repeat('x', 65)]],
        [['vpx_client', '1', 'desktop', [], '26.9.25']],
        [['vpx_client', '1']], [['vpx_daemon', '1']],
    ] as $tags) {
        check(MessageSource::describe(sourceThread($tags)) === '', 'malformed metadata displayed');
    }
    $thread = new Thread();
    $thread->headers = 'Nostr-Tags: not JSON';
    check(MessageSource::describe($thread) === '', 'malformed JSON displayed');
});

runCase('display escapes metadata and keeps it separate from refreshed device labels', function () {
    $sender = (object) ['pubkey' => str_repeat('a', 64), 'label' => 'Laptop'];
    $source = MessageSource::describe(sourceThread([
        ['vpx_client', '1', 'desktop', '<script>bad()</script>', '26.9.25'],
    ]));
    $html = view('nostr::partials.thread_sender', compact('sender', 'source'))->render();
    check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'metadata was not escaped');
    check(strpos($html, '>Laptop</span>') !== false && strpos($html, 'class="nostr-message-source"') !== false,
        'metadata shares the replaceable device label');
});

runCase('production Rust encrypted log carries its original message source into FreeScout', function () {
    $fixture = json_decode(file_get_contents(__DIR__.'/vectors/vpx-log.json'), true);
    $private = $fixture['recipient_private_test_key'];
    $opened = GiftWrap::unwrap($fixture['wrap'], $private, Keys::pubkeyFromPrivate($private));
    $rumor = $opened['rumor'];
    check($fixture['wrap']['tags'] === [['p', Keys::pubkeyFromPrivate($private)]] && $opened['seal']['tags'] === [],
        'metadata leaked outside the encrypted rumor');
    $source = MessageSource::describe(sourceThread(LogAttachment::headerTags($rumor)));
    check(strpos($source, 'Windows · 12VPX Neo 26.9.25') === 0, 'Rust client metadata was not displayed');
    check(strpos($source, 'Daemon: ') !== false, 'Rust daemon metadata was not displayed');
    check(strpos($rumor['content'], 'Windows') === false && count(LogAttachment::extract($rumor)) === 1,
        'metadata changed the message body or attachment');
});

exit($failures ? 1 : 0);
