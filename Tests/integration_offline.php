<?php
// Offline integration test: drives the settings pages, the incoming handler, customer keys,
// merge, NIP-05 and the outgoing failure path through the FreeScout kernel. Everything runs in a
// transaction that is rolled back. Usage (from the FreeScout root): php Modules/Nostr/Tests/integration_offline.php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.disable_browser_check' => true]);
// Mirror a production php.ini: core views trip PHP 8.5 deprecations otherwise.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\IncomingMessageHandler;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\OutgoingMessageSender;

$fail = 0;
function check($name, $cond, $extra = '') { global $fail; echo ($cond ? "ok   " : "FAIL ") . $name . ($extra !== '' && !$cond ? " -- $extra" : '') . "\n"; if (!$cond) $fail++; }
function req($kernel, $method, $uri, $params = []) {
    $request = Request::create(config('app.url') . $uri, $method, $params, [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36']);
    $request->setLaravelSession(app('session.store'));
    $response = $kernel->handle($request);
    return $response;
}

ob_start();
\DB::beginTransaction();
try {
    $admin = User::where('role', User::ROLE_ADMIN)->first();
    \Auth::login($admin);
    $mailbox = \App\Mailbox::first();
    $mid = $mailbox->id;

    // Settings page before any key.
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('settings page renders', $r->getStatusCode() === 200 && strpos($r->getContent(), 'Generate keypair') !== false, $r->getStatusCode());

    // Generate a key.
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'generate']);
    if ($r->getStatusCode() !== 302) { echo "     generate -> ".$r->getStatusCode()." ".substr(preg_replace('/\s+/', ' ', strip_tags($r->getContent())), 0, 600)."\n"; }
    $cfg = NostrMailbox::forMailbox($mid, false);
    check('keypair generated', $r->getStatusCode() === 302 && $cfg && $cfg->pubkey && Keys::pubkeyFromPrivate($cfg->getPrivateKey()) === $cfg->pubkey);
    check('private key stored encrypted', strpos($cfg->private_key, $cfg->getPrivateKey()) === false);

    // Save settings.
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", [
        '_token' => csrf_token(), 'action' => 'save', 'enabled' => '1',
        'inbox_relays' => "ws://127.0.0.1:1\nrelay.example.org/", 'announce_relays' => 'ws://127.0.0.1:1',
        'profile_name' => 'Test Support', 'profile_about' => 'Testing', 'profile_picture' => '', 'nip05_name' => 'Support',
        'auto_reply_enabled' => '1', 'auto_reply_text' => 'Thanks, we will get back to you.', 'reopen_days' => '30',
    ]);
    $cfg = NostrMailbox::forMailbox($mid, false);
    check('settings saved', $r->getStatusCode() === 302 && $cfg->enabled && $cfg->getInboxRelays() === ['ws://127.0.0.1:1', 'wss://relay.example.org'] && $cfg->nip05_name === 'support' && $cfg->auto_reply_enabled, json_encode($cfg->toArray()));
    check('announce queued in background', \DB::table('jobs')->where('payload', 'like', '%nostr.announce%')->exists());

    // Validation: enabling without relays fails.
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'save', 'enabled' => '1', 'inbox_relays' => '', 'reopen_days' => '30']);
    check('validation rejects empty inbox relays', $r->getStatusCode() === 302 && session('errors') && session('errors')->has('inbox_relays'));

    // Settings page shows the identity.
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('settings page shows npub', $r->getStatusCode() === 200 && strpos($r->getContent(), $cfg->getNpub()) !== false && strpos($r->getContent(), 'support@') !== false && strpos($r->getContent(), '@freescout.test') !== false, $r->getStatusCode().' '.substr(preg_replace('/\s+/', ' ', strip_tags($r->getContent())), 0, 300));

    // NIP-05.
    $r = req($kernel, 'GET', '/.well-known/nostr.json', ['name' => 'support']);
    $json = json_decode($r->getContent(), true);
    check('nip05 json', $r->getStatusCode() === 200 && ($json['names']['support'] ?? '') === $cfg->pubkey && ($json['relays'][$cfg->pubkey] ?? []) === $cfg->getInboxRelays() && $r->headers->get('Access-Control-Allow-Origin') === '*', $r->getContent());
    $r = req($kernel, 'GET', '/.well-known/nostr.json', ['name' => 'nobody']);
    check('nip05 unknown name is empty', json_decode($r->getContent(), true)['names'] === []);

    // Incoming message from a new customer.
    $custPriv = Keys::generatePrivateKey(); $custPub = Keys::pubkeyFromPrivate($custPriv);
    $handler = new IncomingMessageHandler(function ($m) { echo "     [handler] $m\n"; });
    [$wrap1, $rumor1] = GiftWrap::wrap(['kind' => 14, 'content' => "Hello, my VPN won't connect.\nhttps://example.com/help", 'tags' => [['p', $cfg->pubkey], ['subject', 'VPN problem']]], $custPriv, $cfg->pubkey);
    $customersBefore = Customer::count();
    $thread1 = $handler->handleGiftWrap($cfg, $wrap1, 'wss://relay.example.org');
    check('thread created', $thread1 instanceof Thread);
    $conv = $thread1 ? $thread1->conversation : null;
    check('conversation is chat on nostr channel', $conv && $conv->type == Conversation::TYPE_CHAT && (int) $conv->channel === 90 && $conv->subject === 'VPN problem' && $conv->status == Conversation::STATUS_ACTIVE && $conv->mailbox_id == $mid);
    check('body escaped, linkified and nl2br', $thread1 && strpos($thread1->body, "won&#039;t connect") !== false && strpos($thread1->body, '<br') !== false && preg_match('#<a\s+href="https://example.com/help"#', $thread1->body), $thread1->body ?? '');
    $customer = $conv ? $conv->customer : null;
    check('customer auto-created with short npub name', $customer && Customer::count() === $customersBefore + 1 && $customer->first_name === Keys::shortNpub($custPub));
    check('key linked and mirrored to core channel', CustomerKey::byPubkey($custPub)->customer_id == $customer->id && CustomerChannel::where('channel', 90)->where('channel_id', $custPub)->value('customer_id') == $customer->id && $customer->fresh()->channel == 90);
    $ev = NostrEvent::where('wrap_id', $wrap1['id'])->first();
    check('event recorded', $ev && $ev->rumor_id === $rumor1['id'] && $ev->thread_id == $thread1->id && $ev->conversation_id == $conv->id && $ev->relay === 'wss://relay.example.org' && $ev->pubkey === $custPub);
    check('auto reply and profile fetch queued', \DB::table('jobs')->where('payload', 'like', '%nostr.auto_reply%')->exists() && \DB::table('jobs')->where('payload', 'like', '%nostr.fetch_profile%')->exists());
    check('threads_count and preview maintained', $conv->fresh()->threads_count == 1 && $conv->fresh()->preview !== '');

    // Duplicate delivery from another relay.
    $count = NostrEvent::count();
    check('duplicate wrap ignored', $handler->handleGiftWrap($cfg, $wrap1, 'wss://other') === null && NostrEvent::count() === $count);

    // Second message reopens the same conversation (after it was closed).
    $conv->status = Conversation::STATUS_CLOSED; $conv->save();
    [$wrap2] = GiftWrap::wrap(['kind' => 14, 'content' => 'Still broken', 'tags' => [['p', $cfg->pubkey]]], $custPriv, $cfg->pubkey);
    $thread2 = $handler->handleGiftWrap($cfg, $wrap2, 'wss://relay.example.org');
    check('second message appended and reopened', $thread2 && $thread2->conversation_id == $conv->id && $conv->fresh()->status == Conversation::STATUS_ACTIVE && $conv->fresh()->threads_count == 2);
    check('no second auto reply', \DB::table('jobs')->where('payload', 'like', '%nostr.auto_reply%')->count() === 1);

    // Message from a second key of the same customer goes to the same conversation.
    $cust2Priv = Keys::generatePrivateKey(); $cust2Pub = Keys::pubkeyFromPrivate($cust2Priv);
    CustomerKey::link($customer, $cust2Pub, CustomerKey::SOURCE_MANUAL, 'Android app');
    [$wrap3] = GiftWrap::wrap(['kind' => 14, 'content' => 'From my phone', 'tags' => [['p', $cfg->pubkey]]], $cust2Priv, $cfg->pubkey);
    $thread3 = $handler->handleGiftWrap($cfg, $wrap3, 'wss://relay.example.org');
    check('second key lands in same conversation', $thread3 && $thread3->conversation_id == $conv->id);
    check('core channel still points at first key', CustomerChannel::where('customer_id', $customer->id)->where('channel', 90)->count() === 1);
    check('latest incoming key is the second key', NostrEvent::lastIncoming($conv->id)->pubkey === $cust2Pub);

    // Old conversation: new one after the reopen window.
    Conversation::where('id', $conv->id)->update(['last_reply_at' => now()->subDays(31)]);
    [$wrap4] = GiftWrap::wrap(['kind' => 14, 'content' => 'New issue months later', 'tags' => [['p', $cfg->pubkey]]], $custPriv, $cfg->pubkey);
    $thread4 = $handler->handleGiftWrap($cfg, $wrap4, null);
    check('new conversation after reopen window', $thread4 && $thread4->conversation_id != $conv->id && $thread4->conversation->customer_id == $customer->id);

    // Wrong recipient and wrong kind are rejected.
    [$wrapX] = GiftWrap::wrap(['kind' => 14, 'content' => 'x', 'tags' => [['p', $cust2Pub]]], $custPriv, $cust2Pub);
    check('wrap for someone else rejected', $handler->handleGiftWrap($cfg, $wrapX, null) === null && NostrEvent::where('wrap_id', $wrapX['id'])->value('status') == NostrEvent::STATUS_FAILED);
    [$wrapK] = GiftWrap::wrap(['kind' => 4, 'content' => 'x', 'tags' => [['p', $cfg->pubkey]]], $custPriv, $cfg->pubkey);
    check('unsupported kind logged and skipped', $handler->handleGiftWrap($cfg, $wrapK, null) === null && NostrEvent::where('wrap_id', $wrapK['id'])->value('error') === 'unsupported kind');

    // Kind 15 file message with a fake unreachable URL falls back to text.
    [$wrapF] = GiftWrap::wrap(['kind' => 15, 'content' => 'http://127.0.0.1:1/file.bin', 'tags' => [['p', $cfg->pubkey], ['file-type', 'image/png'], ['encryption-algorithm', 'aes-gcm'], ['decryption-key', bin2hex(random_bytes(32))], ['decryption-nonce', bin2hex(random_bytes(12))]]], $custPriv, $cfg->pubkey);
    $threadF = $handler->handleGiftWrap($cfg, $wrapF, null);
    check('unreachable file message still creates a thread', $threadF && strpos($threadF->body, 'could not be retrieved') !== false, $threadF->body ?? '');

    // Kind 15 with a local encrypted file served from the test via data: not possible; test decrypt path directly.
    $plain = random_bytes(1000); $key = random_bytes(32); $nonce = random_bytes(12); $tag = '';
    $enc = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag) . $tag;
    $tmp = tempnam(sys_get_temp_dir(), 'nostr'); file_put_contents($tmp, $enc);
    // Serve it through PHP's built-in server briefly.
    $port = 18765; $srv = proc_open("php -S 127.0.0.1:$port -t ".escapeshellarg(dirname($tmp))." >/dev/null 2>&1", [], $pipes); usleep(400000);
    [$wrapG] = GiftWrap::wrap(['kind' => 15, 'content' => "http://127.0.0.1:$port/".basename($tmp), 'tags' => [['p', $cfg->pubkey], ['file-type', 'image/png'], ['encryption-algorithm', 'aes-gcm'], ['decryption-key', bin2hex($key)], ['decryption-nonce', bin2hex($nonce)], ['x', hash('sha256', $enc)], ['size', (string) strlen($enc)]]], $custPriv, $cfg->pubkey);
    $threadG = $handler->handleGiftWrap($cfg, $wrapG, null);
    proc_terminate($srv); @unlink($tmp);
    $att = $threadG ? $threadG->attachments()->first() : null;
    check('encrypted file downloaded, decrypted and attached', $att && $att->mime_type === 'image/png' && $threadG->fresh()->has_attachments && $threadG->conversation->fresh()->has_attachments && strpos($threadG->body, 'Sent a file') !== false, json_encode([$att ? $att->mime_type : null, $threadG ? $threadG->fresh()->has_attachments : null, $threadG ? $threadG->conversation->fresh()->has_attachments : null]));
    if ($att) { $path = $att->getLocalFilePath(); check('attachment content matches plaintext', file_exists($path) && file_get_contents($path) === $plain); }

    // Customer pages.
    $r = req($kernel, 'GET', "/customers/{$customer->id}/nostr");
    check('customer nostr tab renders', $r->getStatusCode() === 200 && strpos($r->getContent(), Keys::npub($custPub)) !== false && strpos($r->getContent(), 'Android app') !== false, $r->getStatusCode());
    $r = req($kernel, 'GET', "/customers/{$customer->id}/edit");
    check('profile page shows nostr tab and keys', $r->getStatusCode() === 200 && strpos($r->getContent(), 'customers/'.$customer->id.'/nostr') !== false && strpos($r->getContent(), Keys::shortNpub($cust2Pub)) !== false);
    $newPub = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
    $r = req($kernel, 'POST', "/customers/{$customer->id}/nostr", ['_token' => csrf_token(), 'action' => 'add', 'pubkey' => Keys::npub($newPub), 'label' => 'Laptop']);
    check('key added via form', $r->getStatusCode() === 302 && CustomerKey::byPubkey($newPub) && CustomerKey::byPubkey($newPub)->label === 'Laptop');
    $other = Customer::createWithoutEmail(['first_name' => 'Other']);
    $r = req($kernel, 'POST', "/customers/{$other->id}/nostr", ['_token' => csrf_token(), 'action' => 'add', 'pubkey' => Keys::npub($newPub)]);
    check('key of another customer refused', CustomerKey::byPubkey($newPub)->customer_id == $customer->id && session('flash_error_floating'));
    $r = req($kernel, 'POST', "/customers/{$customer->id}/nostr", ['_token' => csrf_token(), 'action' => 'remove', 'key_id' => CustomerKey::byPubkey($newPub)->id]);
    check('key removed via form', !CustomerKey::byPubkey($newPub));

    // Merge moves keys.
    $other = Customer::createWithoutEmail(['first_name' => 'Dup']);
    $dupPub = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
    CustomerKey::link($other, $dupPub, 'auto');
    $customer->fresh()->mergeWith($other);
    check('merge moves keys to the surviving customer', CustomerKey::byPubkey($dupPub)->customer_id == $customer->id && CustomerChannel::where('customer_id', $other->id)->count() === 0 && CustomerChannel::where('customer_id', $customer->id)->where('channel', 90)->count() === 1);

    // Conversation view renders with the channel tag.
    $r = req($kernel, 'GET', "/conversation/{$conv->id}?folder_id=".$conv->fresh()->folder_id);
    check('conversation page renders with Nostr tag', $r->getStatusCode() === 200 && preg_match('/fs-tag-name[^>]*>.*Nostr/s', $r->getContent()), $r->getStatusCode().' '.$r->headers->get('Location'));
    check('customer sidebar lists keys', strpos($r->getContent(), Keys::shortNpub($custPub)) !== false);

    // Outgoing reply: relays unreachable -> send error recorded on the thread.
    $reply = Thread::createExtended(['type' => Thread::TYPE_MESSAGE, 'body' => '<p>We are <b>on it</b>.<br>Try again &amp; report back.</p>', 'created_by_user_id' => $admin->id], $conv->fresh(), $customer);
    $sender = new OutgoingMessageSender(function ($m) { echo "     [sender] $m\n"; });
    check('thread to text', $sender->threadToText($reply) === "We are on it.\nTry again & report back.", json_encode($sender->threadToText($reply)));
    $t0 = microtime(true);
    $ok = $sender->handleSendReply($conv->fresh(), collect([$reply]));
    $elapsed = microtime(true) - $t0;
    $reply = $reply->fresh();
    check('unreachable relays -> send error on thread', $reply->send_status == \App\SendLog::STATUS_SEND_ERROR && strpos((string) $reply->send_status_data, 'relay') !== false, $reply->send_status.' '.$reply->send_status_data);
    $out = NostrEvent::where('thread_id', $reply->id)->where('direction', NostrEvent::DIRECTION_OUT)->first();
    check('outgoing event recorded as failed with target relays', $out && $out->status == NostrEvent::STATUS_FAILED && isset($out->getRelays()['ws://127.0.0.1:1']) && isset($out->getRelays()['wss://relay.example.org']) && $out->pubkey === $cust2Pub, $out ? $out->relays : 'none');
    check('failure was fast', $elapsed < 20, round($elapsed, 1).'s');
    check('reply targets the latest key (second key)', $out && $out->pubkey === $cust2Pub);

    // Auto reply through the background action with unreachable relays: no line item.
    $before = Thread::where('conversation_id', $thread4->conversation_id)->count();
    $sender->sendAutoReply($thread4->conversation_id, $cfg->id, $custPub);
    check('failed auto reply adds no line item', Thread::where('conversation_id', $thread4->conversation_id)->count() === $before);

    // Settings section (global) renders.
    $r = req($kernel, 'GET', '/app-settings/nostr');
    check('global settings page renders', $r->getStatusCode() === 200 && strpos($r->getContent(), 'Default inbox relays') !== false, $r->getStatusCode());
    $r = req($kernel, 'POST', '/app-settings/nostr', ['_token' => csrf_token(), 'settings' => ['nostr.default_inbox_relays' => "wss://a.example\nb.example", 'nostr.default_announce_relays' => 'wss://c.example']]);
    \Option::$cache = []; // per-process cache; a real request starts fresh
    check('global settings saved as arrays', $r->getStatusCode() === 302 && \Option::get('nostr.default_inbox_relays') === ['wss://a.example', 'wss://b.example'], $r->getStatusCode().' '.json_encode(\Option::get('nostr.default_inbox_relays')));
    $cfg2 = NostrMailbox::forMailbox(999999);
    check('new mailbox config prefilled from defaults', $cfg2->getInboxRelays() === ['wss://a.example', 'wss://b.example'] && $cfg2->getAnnounceRelays() === ['wss://c.example']);

    // Chats list helper.
    check('chat mode available', \Helper::isChatModeAvailable() > 0);
} catch (\Throwable $e) {
    echo "EXCEPTION: ".get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n"; $fail++;
} finally {
    \DB::rollBack();
}
echo $fail ? "$fail FAILED\n" : "ALL OK\n";
ob_end_flush();
