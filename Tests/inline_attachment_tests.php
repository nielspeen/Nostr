<?php

require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Attachment;
use App\Thread;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\InlineAttachments;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\OutgoingMessageSender;
use Modules\Nostr\Services\RelayLimits;

require_once __DIR__.'/../Database/Migrations/2026_09_23_000001_create_nostr_mailboxes_table.php';
(new \CreateNostrMailboxesTable())->up();
\Schema::table('nostr_mailboxes', function ($table) { $table->dateTime('key_created_at')->nullable(); });
\Schema::table('nostr_events', function ($table) { $table->string('mailbox_pubkey')->nullable(); });
require_once $root.'/database/migrations/2018_08_04_063414_create_attachments_table.php';
(new \CreateAttachmentsTable())->up();
\Schema::table('attachments', function ($table) { $table->integer('token_type')->nullable(); });
$directory = sys_get_temp_dir().'/nostr-inline-test-'.bin2hex(random_bytes(8));
config(['filesystems.disks.private' => ['driver' => 'local', 'root' => $directory]]);
app('filesystem')->set('private', app('filesystem')->createLocalDriver(config('filesystems.disks.private')));

class InlineTestSender extends OutgoingMessageSender
{
    public $result;
    public $failure;
    public function targetRelays(NostrMailbox $cfg, $pubkey, array $extra = []) { return []; }
    public function sendText(NostrMailbox $cfg, $pubkey, $text, array $options = [])
    {
        return $this->result = parent::sendText($cfg, $pubkey, $text, $options);
    }
    protected function fail(Thread $thread, $message) { $this->failure = $message; }
}

function inlineThread(array $files, $body = 'See the attached files')
{
    $thread = new Thread();
    $thread->id = 123;
    $thread->body = $body;
    $thread->setRelation('all_attachments', collect($files));
    $thread->setRelation('created_by_user', null);

    return $thread;
}

function inlineAccount()
{
    $cfg = new NostrMailbox();
    $cfg->mailbox_id = 1;
    $cfg->setPrivateKey(str_pad('1', 64, '0', STR_PAD_LEFT));
    $cfg->save();
    $customer = customer();
    CustomerKey::link($customer, Keys::pubkeyFromPrivate(str_pad('2', 64, '0', STR_PAD_LEFT)));

    return conversation($customer);
}

$failures = 0;
$fixturePath = $argv[1] ?? null;
try {
    runCase('image and text travel in encrypted tags with no hosted links or header payloads', function () use ($fixturePath) {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $image = Attachment::create('screenshot.png', 'image/png', null, $png, null, true, 123);
        $text = Attachment::create('steps.txt', 'text/plain', null, "First step\n第二步", null, false, 123);
        $archive = Attachment::create('archive.zip', 'application/zip', null, "PK\3\4example", null, false, 123);
        $thread = inlineThread([$image, $text, $archive], '<p>See the attached files</p><a href="'.e($image->url()).'"><img src="'.e($image->url()).'"></a>');
        $sender = new InlineTestSender();
        $sender->sendThread(inlineAccount(), $thread);
        check($sender->result !== null, 'attachment reply was rejected: '.$sender->failure);
        $key = str_pad('2', 64, '0', STR_PAD_LEFT);
        $rumor = GiftWrap::unwrap($sender->result['wrap'], $key, Keys::pubkeyFromPrivate($key))['rumor'];
        check($rumor['content'] === 'See the attached files', 'file URL leaked into reply text');
        $tags = array_values(array_filter($rumor['tags'], function ($tag) { return $tag[0] === InlineAttachments::TAG; }));
        check(count($tags) === 3 && base64_decode($tags[0][4], true) === $png && base64_decode($tags[1][4], true) === "First step\n第二步"
            && $tags[2][3] === 'application/zip' && base64_decode($tags[2][4], true) === "PK\3\4example", 'attachment bytes changed');
        check(strpos($thread->headers, $tags[0][4]) === false && strpos($thread->headers, 'vpx_attachment') === false, 'file payload leaked into headers');
        check(count($sender->result['wrap']['tags']) === 1, 'file metadata leaked outside encryption');
        if ($fixturePath) file_put_contents($fixturePath, json_encode(['recipient_private_test_key' => $key,
            'sender_public_key' => Keys::pubkeyFromPrivate(str_pad('1', 64, '0', STR_PAD_LEFT)),
            'wrap' => $sender->result['wrap'], 'attachments' => array_map(function ($tag) {
                return ['name' => $tag[2], 'mime_type' => $tag[3], 'data' => $tag[4]];
            }, $tags)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    });
    runCase('invalid, missing and remote files reject the entire reply before publication', function () {
        $conv = inlineAccount();
        $bad = Attachment::create('bad.png', 'image/png', null, 'not a PNG', null, false, 123);
        $good = Attachment::create('steps.txt', 'text/plain', null, 'Steps', null, false, 123);
        foreach ([inlineThread([$good, $bad]), inlineThread([], '<img src="https://host.example/image.png">'),
            inlineThread([], '<video src="https://host.example/movie.mp4"></video>'),
            inlineThread([$good], '<img src="https://host.example/image.png" alt="'.e($good->url()).'">')] as $thread) {
            $sender = new InlineTestSender();
            check(!$sender->sendThread($conv, $thread) && $sender->result === null && $sender->failure, 'part of rejected reply was published');
        }
        $good->file_dir = 'missing/';
        $sender = new InlineTestSender();
        check(!$sender->sendThread($conv, inlineThread([$good])) && $sender->result === null, 'missing file was replaced by URL');
    });
    runCase('files without an inline preview are carried as bytes without a URL fallback', function () {
        $file = Attachment::create('archive.zip', 'application/zip', null, "PK\3\4example", null, false, 123);
        $sender = new InlineTestSender();
        $sender->sendThread(inlineAccount(), inlineThread([$file]));
        check($sender->result !== null, 'generic attachment was rejected');
        $tags = $sender->result['rumor']['tags'];
        $tag = end($tags);
        check($tag[3] === 'application/zip' && base64_decode($tag[4]) === "PK\3\4example", 'generic file bytes changed');
        check(strpos($sender->result['rumor']['content'], 'http') === false, 'file became a hosted link');
    });
    runCase('attachment-only messages and ordinary website links remain supported', function () {
        $file = Attachment::create('steps.txt', 'text/plain', null, 'Steps', null, false, 123);
        $sender = new InlineTestSender();
        $sender->sendThread(inlineAccount(), inlineThread([$file], ''));
        check($sender->result !== null && $sender->result['rumor']['content'] === '', 'attachment-only message rejected');
        check($sender->threadToText(inlineThread([], '<a href="https://example.com/help">Help</a>')) === 'Help (https://example.com/help)', 'ordinary link removed');
        $encodedUrl = str_replace('&', '&#38;', $file->url());
        check($sender->threadToText(inlineThread([$file], '<a href="'.$encodedUrl.'">steps.txt</a>')) === 'steps.txt', 'entity-encoded attachment URL leaked');
    });
    runCase('encrypted attachments use the one MiB default and still respect lower relay limits', function () {
        \Option::$cache = [];
        $file = Attachment::create('steps.txt', 'text/plain', null, str_repeat('x', 100000), null, false, 123);
        $sender = new InlineTestSender();
        $sender->sendThread(inlineAccount(), inlineThread([$file]));
        $wrap = $sender->result['wrap'];
        check(strlen(GiftWrap::encode(['EVENT', $wrap])) > 65536, 'fixture did not exceed old budget');
        check((new RelayLimits())->error($wrap, []) === null, 'new default rejected useful attachment');
        \Cache::put('nostr.relay_limits.'.hash('sha256', 'wss://small.example'),
            ['retry_at' => time() + 1000, 'limits' => ['max_message_length' => 65536]], now()->addHour());
        check((new RelayLimits())->error($wrap, ['wss://small.example']) !== null, 'lower relay limit ignored');
        \Option::set('nostr.max_message_bytes', strlen(GiftWrap::encode(['EVENT', $wrap])) - 1);
        \Option::$cache = [];
        check((new RelayLimits())->error($wrap, []) !== null, 'encryption overhead ignored');
    });
    runCase('unsafe names and mismatched file types are rejected', function () {
        foreach (['../escape', 'x/y.txt', 'x\\y.txt', 'x:stream', 'bad.', "bad\nname", '..'] as $name) {
            check(!InlineAttachments::validName($name), 'unsafe filename accepted');
        }
        check(!InlineAttachments::validContent('not png', 'image/png'), 'fake image accepted');
        check(!InlineAttachments::validContent("bad\0text", 'text/plain'), 'binary text accepted');
        check(!InlineAttachments::validContent("\xff", 'text/plain'), 'invalid UTF-8 accepted');
    });
} finally { \File::deleteDirectory($directory); }
exit($failures ? 1 : 0);
