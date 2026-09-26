<?php

// FreeScout attachment storage, isolated from real customers, files and relays.
require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Attachment;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\LogAttachment;

require_once $root.'/database/migrations/2018_08_04_063414_create_attachments_table.php';
(new \CreateAttachmentsTable())->up();
\Schema::table('attachments', function ($table) { $table->integer('token_type')->nullable(); });
$directory = sys_get_temp_dir().'/nostr-log-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
config(['filesystems.disks.private' => ['driver' => 'local', 'root' => $directory]]);
app('filesystem')->set('private', app('filesystem')->createLocalDriver(config('filesystems.disks.private')));
$failures = 0;

try {
    runCase('Rust log becomes a normal downloadable FreeScout text attachment', function () {
        $fixture = json_decode(file_get_contents(__DIR__.'/vectors/vpx-log.json'), true);
        $key = $fixture['recipient_private_test_key'];
        $rumor = GiftWrap::unwrap($fixture['wrap'], $key, Keys::pubkeyFromPrivate($key))['rumor'];
        $entry = LogAttachment::extract($rumor)[0];
        $contents = base64_decode($entry['data'], true);
        $attachment = Attachment::create($entry['file_name'], $entry['mime_type'], null, $contents, null, false, 123);
        check($attachment && $attachment->fresh()->thread_id === 123, 'Attachment was not stored for its thread');
        check(!$attachment->embedded && $attachment->mime_type === 'text/plain', 'Log was embedded instead of attached');
        check(\Storage::disk('private')->get($attachment->getStorageFilePath()) === $contents, 'Stored log contents changed');
        check($attachment->size === strlen($contents), 'Wrong attachment size');
    });
} finally {
    \File::deleteDirectory($directory);
}
exit($failures ? 1 : 0);
