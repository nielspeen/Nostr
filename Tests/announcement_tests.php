<?php
require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use Modules\Nostr\Entities\Announcement;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\AnnouncementPublisher;
use Modules\Nostr\Services\EventBuilder;

require_once __DIR__.'/../Database/Migrations/2026_09_23_000001_create_nostr_mailboxes_table.php';
(new CreateNostrMailboxesTable())->up();
Schema::table('nostr_mailboxes', function ($table) { $table->dateTime('key_created_at')->nullable(); });
require_once __DIR__.'/../Database/Migrations/2026_09_27_000001_create_nostr_announcements_table.php';
(new CreateNostrAnnouncementsTable())->up();

class TestAnnouncementPublisher extends AnnouncementPublisher
{
    public $sent = [];
    protected function deliver(array $event, array $relays, $key)
    {
        check(Announcement::where('identifier', EventBuilder::firstTag($event, 'd'))->exists(), 'not saved before sending');
        $this->sent[] = $event;
        return array_fill_keys($relays, ['ok' => false, 'message' => 'offline']);
    }
}

$failures = 0;
runCase('signed editable announcement and retry preserve identity', function () use ($argv) {
    $cfg = new NostrMailbox();
    $cfg->mailbox_id = 1;
    $cfg->setPrivateKey(str_pad('1', 64, '0', STR_PAD_LEFT));
    $cfg->setInboxRelays(['wss://relay.example']);
    $cfg->save();
    $publisher = new TestAnnouncementPublisher();
    $input = ['title' => 'Service update', 'summary' => 'Investigating', 'body' => "We are investigating.\n更新", 'incident' => true];
    $event = $publisher->build($input, 'incident-one', $cfg->getPrivateKey());
    check(EventBuilder::verify($event), 'signature invalid');
    check($event['kind'] === 30023 && EventBuilder::firstTag($event, 't') === 'vpx-announcement', 'incorrect kind or marker');
    $item = new Announcement();
    $item->mailbox_id = 1;
    $item->identifier = 'incident-one';
    $item->event = $event;
    $publisher->publish($item, $cfg);
    $publisher->publish($item->fresh(), $cfg);
    check($publisher->sent[0] === $publisher->sent[1], 'retry changed signed event');
    $input['incident'] = false;
    $updated = $publisher->build($input, 'incident-one', $cfg->getPrivateKey(), $event);
    check($updated['created_at'] > $event['created_at'], 'same-second update not newer');
    check(EventBuilder::firstTag($updated, 'published_at') === EventBuilder::firstTag($event, 'published_at'), 'original date changed');
    check(EventBuilder::firstTag($updated, 'vpx-incident') === null, 'resolved incident flag remains');
    if (isset($argv[1])) file_put_contents($argv[1], json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
});
runCase('invalid oversized and expired content is rejected', function () {
    $publisher = new AnnouncementPublisher();
    foreach ([['title' => ''], ['body' => str_repeat('x', 16385)], ['title' => str_repeat('界', 101)], ['expires_at' => '2000-01-01T00:00']] as $bad) {
        try {
            $publisher->build(array_merge(['title' => 'Update', 'body' => 'Body'], $bad), 'test', str_pad('1', 64, '0', STR_PAD_LEFT));
            throw new RuntimeException('invalid announcement accepted');
        } catch (InvalidArgumentException $e) {}
    }
});

runCase('announcement page scopes edits to its mailbox and requires permission', function () {
    $controller = new \Modules\Nostr\Http\Controllers\AnnouncementsController();
    $view = $controller->index(1, \Illuminate\Http\Request::create('/'));
    check($view->getName() === 'nostr::announcements', 'wrong view');
    check($view->getData()['announcements']->total() === 0, 'unexpected rows');
    $foreign = new Announcement();
    $foreign->mailbox_id = 2; $foreign->identifier = 'foreign'; $foreign->event = [];
    $foreign->save();
    try {
        $controller->index(1, \Illuminate\Http\Request::create('/?edit='.$foreign->id));
        throw new RuntimeException('foreign announcement exposed');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {}
    app('auth')->setUser(new class extends \App\User {
        public function isAdmin() { return false; }
        public function canManageMailbox($mailbox) { return false; }
    });
    foreach (['index', 'publish'] as $method) {
        try {
            $controller->$method(1, \Illuminate\Http\Request::create('/'));
            throw new RuntimeException('unauthorized announcement access');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {}
    }
});
exit($failures ? 1 : 0);
