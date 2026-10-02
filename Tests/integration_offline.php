<?php
// Offline checks of the module on Tallport's Nostr channel: logs and files in
// messages, Show original, agent names, app versions, CustomApp labels and
// announcements. Runs in a rolled back transaction; relays are on a closed port.
// Usage (from the Tallport root, with the module active): php Modules/Nostr/Tests/integration_offline.php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.disable_browser_check' => true, 'app.two_factor_required' => false]);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use App\Nostr\CustomerKey;
use App\Nostr\GiftWrap;
use App\Nostr\IncomingMessageHandler;
use App\Nostr\Keys;
use App\Nostr\NostrMailbox;
use App\Nostr\OutgoingMessageSender;
use App\Thread;
use App\User;
use Illuminate\Http\Request;

$fail = 0;
function check($name, $cond, $extra = '') { global $fail; echo ($cond ? 'ok   ' : 'FAIL ').$name.($extra !== '' && !$cond ? " -- $extra" : '')."\n"; if (!$cond) { $fail++; } }
function req($kernel, $method, $uri, $params = []) {
    $request = Request::create(config('app.url').$uri, $method, $params);
    $request->setLaravelSession(app('session.store'));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}

if (!\App\Module::isActive('nostr')) {
    echo "Activate the module first (Manage » Modules).\n";
    exit(1);
}

ob_start();
\DB::beginTransaction();
try {
    $admin = User::where('role', User::ROLE_ADMIN)->first();
    \Auth::login($admin);
    $mailbox = \App\Mailbox::first();
    NostrMailbox::where('mailbox_id', $mailbox->id)->delete();
    $cfg = NostrMailbox::forMailbox($mailbox->id);
    $cfg->setPrivateKey(Keys::generatePrivateKey());
    $cfg->enabled = true;
    $cfg->setInboxRelays(['ws://127.0.0.1:1']);
    $cfg->setAnnounceRelays(['ws://127.0.0.1:1']);
    $cfg->save();
    $customerPrivate = Keys::generatePrivateKey();
    $customerPubkey = Keys::pubkeyFromPrivate($customerPrivate);
    $handler = new IncomingMessageHandler();
    $receive = function ($content, $tags) use ($cfg, $customerPrivate, $handler) {
        [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => $content, 'tags' => array_merge([['p', $cfg->pubkey]], $tags)], $customerPrivate, $cfg->pubkey);

        return $handler->handleGiftWrap($cfg, $wrap, 'wss://relay.example.org');
    };

    // Logs from the app.
    $thread = $receive('Here are my logs', [['vpx_log', '1', 'vpx-logs-20261007-120000.txt', "line 1\nline 2"], ['vpx_client', '1', 'desktop', 'Windows 11', '2.3.0']]);
    $attachment = $thread ? $thread->attachments()->first() : null;
    check('log attached', $attachment && $attachment->file_name === 'vpx-logs-20261007-120000.txt' && $attachment->getFileContents() === "line 1\nline 2");
    check('log kept out of Show original', strpos((string) $thread->fresh()->headers, 'line 1') === false && strpos((string) $thread->fresh()->headers, 'vpx_client') !== false);
    check('app and version shown', \Eventy::filter('nostr.message_source', '', $thread->fresh()) === 'Windows 11 · 12VPX Neo 2.3.0', \Eventy::filter('nostr.message_source', '', $thread->fresh()));
    $bad = $receive('Bad logs', [['vpx_log', '2', 'x.txt', 'x']]);
    check('invalid log noted', $bad && strpos($bad->body, 'could not be attached') !== false, $bad->body ?? '');

    // Replies: files inside the message and the agent's name.
    $conversation = $thread->conversation;
    $reply = Thread::createExtended(['type' => Thread::TYPE_MESSAGE, 'body' => '<p>See the file</p>', 'created_by_user_id' => $admin->id], $conversation, $conversation->customer);
    \App\Attachment::create('steps.txt', 'text/plain', null, 'Step 1', null, false, $reply->id);
    $reply = $reply->fresh();
    $tags = \Eventy::filter('nostr.reply_attachment_tags', false, $reply);
    check('files become vpx_attachment tags', is_array($tags) && ($tags[0][0] ?? '') === 'vpx_attachment' && base64_decode($tags[0][4]) === 'Step 1');
    $result = (new OutgoingMessageSender())->sendText($cfg, $customerPubkey, 'See the file', ['thread_id' => $reply->id, 'attachments' => $tags]);
    check('agent name tag', in_array(['support_agent', $admin->first_name], $result['rumor']['tags']));
    check('files kept out of Show original', !in_array('vpx_attachment', array_column(IncomingMessageHandler::headerTags($result['rumor']['tags']), 0)));
    $auto = (new OutgoingMessageSender())->sendText($cfg, $customerPubkey, 'Automated', []);
    check('no agent name on automated messages', !in_array('support_agent', array_column($auto['rumor']['tags'], 0)));

    // CustomApp: keys in the payload, labels from the response.
    $customer = $conversation->customer;
    $payload = \Eventy::filter('customapp.payload', ['customer' => [], 'ticket' => []], $conversation, $customer, $mailbox);
    check('payload has the keys', $payload['customer']['nostr_pubkeys'] === [$customerPubkey] && $payload['ticket']['nostr_pubkey'] === $customerPubkey);
    \Eventy::action('customapp.response', ['customer' => ['nostr_keys' => [['pubkey' => $customerPubkey, 'label' => 'Work laptop']]]], $conversation, $customer);
    check('labels from the response', CustomerKey::byPubkey($customerPubkey)->label === 'Work laptop');

    // Announcements.
    $r = req($kernel, 'GET', '/mailbox/settings/'.$mailbox->id.'/nostr/announcements');
    check('announcements page', $r->getStatusCode() === 200, $r->getStatusCode().' '.substr(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(style|script)\b.*?</\1>#s', '', $r->getContent()))), 0, 700));
    $r = req($kernel, 'POST', '/mailbox/settings/'.$mailbox->id.'/nostr/announcements', ['_token' => csrf_token(), 'title' => 'Maintenance', 'summary' => '', 'body' => 'Tonight 22:00 UTC']);
    $announcement = \Modules\Nostr\Entities\Announcement::where('mailbox_id', $mailbox->id)->first();
    check('announcement signed and stored', $announcement && $announcement->event['kind'] === 30023 && \App\Nostr\EventBuilder::verify($announcement->event));
    $r = req($kernel, 'GET', '/mailbox/settings/'.$mailbox->id.'/nostr');
    check('menu links to announcements', strpos($r->getContent(), '/nostr/announcements') !== false);
} catch (\Throwable $e) {
    echo 'EXCEPTION: '.get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
    $fail++;
} finally {
    \DB::rollBack();
}
echo $fail ? "$fail FAILED\n" : "ALL OK\n";
ob_end_flush();
exit($fail ? 1 : 0);
