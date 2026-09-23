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
    // Like the real front controller: lets the session age its flash data.
    $kernel->terminate($request, $response);
    return $response;
}

ob_start();
\DB::beginTransaction();
try {
    $admin = User::where('role', User::ROLE_ADMIN)->first();
    \Auth::login($admin);
    $mailbox = \App\Mailbox::first();
    $mid = $mailbox->id;

    // Start from a mailbox without Nostr configuration (rolled back with the transaction).
    NostrMailbox::where('mailbox_id', $mid)->delete();
    \Modules\Nostr\Entities\MailboxKey::where('mailbox_id', $mid)->delete();

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
        'profile_name' => 'Test Support', 'profile_about' => 'Testing', 'profile_picture' => '', 'nip05' => 'Support@Example.COM',
        'auto_reply_enabled' => '1', 'auto_reply_text' => 'Thanks, we will get back to you.', 'reopen_days' => '30',
    ]);
    $cfg = NostrMailbox::forMailbox($mid, false);
    check('settings saved', $r->getStatusCode() === 302 && $cfg->enabled && $cfg->getInboxRelays() === ['ws://127.0.0.1:1', 'wss://relay.example.org'] && $cfg->nip05 === 'support@example.com' && $cfg->auto_reply_enabled, json_encode($cfg->toArray()));
    check('announce queued in background', \DB::table('jobs')->where('payload', 'like', '%nostr.announce%')->exists());

    // Validation: enabling without relays fails.
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'save', 'enabled' => '1', 'inbox_relays' => '', 'reopen_days' => '30']);
    check('validation rejects empty inbox relays', $r->getStatusCode() === 302 && session('errors') && session('errors')->has('inbox_relays'));

    // Settings page shows the identity.
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('settings page shows npub', $r->getStatusCode() === 200 && strpos($r->getContent(), $cfg->getNpub()) !== false && strpos($r->getContent(), 'support@example.com') !== false && strpos($r->getContent(), 'Host this file at https://example.com/.well-known/nostr.json') !== false, $r->getStatusCode().' '.substr(preg_replace('/\s+/', ' ', strip_tags($r->getContent())), 0, 300));

    // Listener status panel.
    \Option::set('nostr.listener', null);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('listener panel: never started', strpos($r->getContent(), 'Not started yet') !== false && strpos($r->getContent(), 'not connected') !== false, $r->getStatusCode());
    \Modules\Nostr\Services\ListenerStatus::write(['pid' => 4242, 'host' => 'box', 'started_at' => time() - 100, 'lifetime' => 1200, 'ends_at' => time() + 1100, 'stopped_at' => null, 'stop_reason' => null,
        'connections' => [['mailbox_id' => $mid, 'url' => 'ws://127.0.0.1:1', 'state' => 'connected', 'since' => time() - 90, 'caught_up' => true, 'authed' => false, 'events' => 3, 'last_event_at' => time() - 10, 'error' => null, 'retry_in' => null],
                          ['mailbox_id' => $mid, 'url' => 'wss://relay.example.org', 'state' => 'reconnecting', 'since' => null, 'caught_up' => false, 'authed' => false, 'events' => 0, 'last_event_at' => null, 'error' => 'Connection refused', 'retry_in' => 20]]]);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    $c = $r->getContent();
    check('listener panel: running with relay details', strpos($c, '>Running<') !== false && strpos($c, 'Process 4242 on box') !== false && strpos($c, '>connected<') !== false && strpos($c, '>reconnecting<') !== false && strpos($c, 'Connection refused') !== false && strpos($c, 'retry in 20 s') !== false, substr(preg_replace('/\s+/', ' ', strip_tags($c)), 0, 200));
    \Option::set('nostr.listener', ['pid' => 1, 'heartbeat_at' => time() - 1000, 'started_at' => time() - 2000, 'connections' => []]);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('listener panel: stale heartbeat', strpos($r->getContent(), 'Not responding') !== false);
    \Option::set('nostr.listener', ['pid' => 1, 'heartbeat_at' => time() - 30, 'stopped_at' => time() - 30, 'stop_reason' => 'lifetime', 'connections' => []]);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('listener panel: scheduled restart', strpos($r->getContent(), '>Restarting<') !== false);
    \Option::set('nostr.listener', ['pid' => 1, 'heartbeat_at' => time() - 3000, 'stopped_at' => time() - 3000, 'stop_reason' => 'signal', 'connections' => []]);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('listener panel: stopped', strpos($r->getContent(), '>Stopped<') !== false);
    \Option::set('nostr.listener', null);

    // Key lifecycle: the second generate is refused, replacing needs password + phrase, old key is retired.
    $firstPub = $cfg->pubkey;
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'generate']);
    check('second generate refused', NostrMailbox::forMailbox($mid, false)->pubkey === $firstPub && session('flash_error_floating'));
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'replace', 'confirm' => 'REPLACE']);
    check('replace without password refused', NostrMailbox::forMailbox($mid, false)->pubkey === $firstPub);
    $admin->password = \Hash::make('correct horse'); $admin->save();
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'replace', 'password' => 'wrong', 'confirm' => 'REPLACE']);
    check('replace with wrong password refused', NostrMailbox::forMailbox($mid, false)->pubkey === $firstPub);
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'replace', 'password' => 'correct horse', 'confirm' => 'replace please']);
    check('replace with wrong phrase refused', NostrMailbox::forMailbox($mid, false)->pubkey === $firstPub);
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'reveal']);
    check('reveal without password refused', !session('nostr_reveal_nsec'));
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'reveal', 'password' => 'correct horse']);
    check('reveal with password shows nsec once', session('nostr_reveal_nsec') === $cfg->getNsec());
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('nsec rendered on the page after reveal', strpos($r->getContent(), $cfg->getNsec()) !== false);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('nsec not rendered again', strpos($r->getContent(), $cfg->getNsec()) === false);
    $firstPriv = $cfg->getPrivateKey();
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'replace', 'password' => 'correct horse', 'confirm' => ' replace ']);
    $cfg = NostrMailbox::forMailbox($mid, false);
    $retired = $cfg->getRetiredKeys();
    check('replace with password and phrase works', $r->getStatusCode() === 302 && $cfg->pubkey !== $firstPub && Keys::pubkeyFromPrivate($cfg->getPrivateKey()) === $cfg->pubkey);
    check('old key retired with its private key', count($retired) === 1 && $retired[0]->pubkey === $firstPub && $retired[0]->getPrivateKey() === $firstPriv && $retired[0]->retired_at);
    check('listener subscribes for both keys', $cfg->getAllPubkeys() === [$cfg->pubkey, $firstPub] && $cfg->getPrivateKeyFor($firstPub) === $firstPriv);
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'replace', 'replace_mode' => 'import', 'nsec' => Keys::nsec($firstPriv), 'password' => 'correct horse', 'confirm' => 'REPLACE']);
    check('re-importing a retired key refused', NostrMailbox::forMailbox($mid, false)->getRetiredKeys()->count() === 1 && session('flash_error_floating'));
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('settings page lists the retired key', $r->getStatusCode() === 200 && strpos($r->getContent(), Keys::npub($firstPub)) !== false && strpos($r->getContent(), 'Type DELETE') !== false, $r->getStatusCode());

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
    $h = $thread1->fresh()->headers;
    check('pseudo headers stored on the thread', strpos($h, 'X-Nostr-Relay: wss://relay.example.org') !== false && strpos($h, 'X-Nostr-Wrap-Id: '.$wrap1['id']) !== false && strpos($h, 'X-Nostr-Rumor-Id: '.$rumor1['id']) !== false && strpos($h, 'X-Nostr-From: '.Keys::npub($custPub)) !== false && strpos($h, 'X-Nostr-Subject: VPN problem') !== false, $h);
    $r = req($kernel, 'GET', "/conversation/{$conv->id}"); // warm up route for ajax below
    $r = req($kernel, 'GET', '/conversation/ajax-html/show_original?thread_id='.$thread1->id);
    check('show original has a headers tab', $r->getStatusCode() === 200 && strpos($r->getContent(), 'X-Nostr-Relay') !== false, $r->getStatusCode().' '.substr(strip_tags($r->getContent()), 0, 200));

    // Duplicate delivery from another relay.
    $count = NostrEvent::count();
    check('duplicate wrap ignored', $handler->handleGiftWrap($cfg, $wrap1, 'wss://other') === null && NostrEvent::count() === $count);

    // The same rumor delivered in a second, differently wrapped gift wrap is a duplicate too.
    $rumorFixed = ['kind' => 14, 'content' => 'Re-wrapped once per relay', 'tags' => [['p', $cfg->pubkey]], 'created_at' => time() - 5];
    [$wrapA, $rumorA] = GiftWrap::wrap($rumorFixed, $custPriv, $cfg->pubkey);
    [$wrapB, $rumorB] = GiftWrap::wrap($rumorFixed, $custPriv, $cfg->pubkey);
    check('two wraps of one rumor share the rumor id', $wrapA['id'] !== $wrapB['id'] && $rumorA['id'] === $rumorB['id']);
    $threadA = $handler->handleGiftWrap($cfg, $wrapA, 'wss://relay-a');
    $threadB = $handler->handleGiftWrap($cfg, $wrapB, 'wss://relay-b');
    check('second wrap of the same rumor ignored', $threadA && $threadB === null && NostrEvent::where('wrap_id', $wrapB['id'])->value('error') === 'duplicate' && $conv->fresh()->threads_count == 2);
    check('claim rows carry the final state', NostrEvent::where('wrap_id', $wrapA['id'])->value('status') == NostrEvent::STATUS_OK && NostrEvent::where('status', NostrEvent::STATUS_PROCESSING)->count() === 0);

    // Second message reopens the same conversation (after it was closed).
    $conv->status = Conversation::STATUS_CLOSED; $conv->save();
    [$wrap2] = GiftWrap::wrap(['kind' => 14, 'content' => 'Still broken', 'tags' => [['p', $cfg->pubkey]]], $custPriv, $cfg->pubkey);
    $thread2 = $handler->handleGiftWrap($cfg, $wrap2, 'wss://relay.example.org');
    check('second message appended and reopened', $thread2 && $thread2->conversation_id == $conv->id && $conv->fresh()->status == Conversation::STATUS_ACTIVE && $conv->fresh()->threads_count == 3);
    check('no second auto reply', \DB::table('jobs')->where('payload', 'like', '%nostr.auto_reply%')->count() === 1);

    // Message from a second key of the same customer goes to the same conversation.
    $cust2Priv = Keys::generatePrivateKey(); $cust2Pub = Keys::pubkeyFromPrivate($cust2Priv);
    CustomerKey::link($customer, $cust2Pub, CustomerKey::SOURCE_MANUAL, 'Android app');
    [$wrap3] = GiftWrap::wrap(['kind' => 14, 'content' => 'From my phone', 'tags' => [['p', $cfg->pubkey]]], $cust2Priv, $cfg->pubkey);
    $thread3 = $handler->handleGiftWrap($cfg, $wrap3, 'wss://relay.example.org');
    check('second key lands in same conversation', $thread3 && $thread3->conversation_id == $conv->id);
    check('core channel still points at first key', CustomerChannel::where('customer_id', $customer->id)->where('channel', 90)->count() === 1);
    check('latest incoming key is the second key', NostrEvent::lastIncoming($conv->id)->pubkey === $cust2Pub);

    // A customer who still uses the retired key reaches the same conversation, answered from that key.
    [$wrapOld] = GiftWrap::wrap(['kind' => 14, 'content' => 'Sent to your old key', 'tags' => [['p', $firstPub]]], $custPriv, $firstPub);
    $threadOld = $handler->handleGiftWrap($cfg, $wrapOld, 'wss://relay.example.org');
    $evOld = NostrEvent::where('wrap_id', $wrapOld['id'])->first();
    check('message to retired key still received', $threadOld && $threadOld->conversation_id == $conv->id && $evOld && $evOld->mailbox_pubkey === $firstPub);
    $senderTest = new OutgoingMessageSender();
    $wrongKeyMsg = GiftWrap::wrap(['kind' => 14, 'content' => 'x', 'tags' => [['p', $firstPub]]], $custPriv, $firstPub)[0];
    $r0 = $senderTest->sendText($cfg, $custPub, 'reply from old key', ['from_pubkey' => $firstPub, 'conversation_id' => $conv->id]);
    check('reply goes out from the retired key', $r0['event']->mailbox_pubkey === $firstPub);
    $r0 = $senderTest->sendText($cfg, $custPub, 'reply from unknown key', ['from_pubkey' => str_repeat('ab', 32), 'conversation_id' => $conv->id]);
    check('unknown from key falls back to current key', $r0['event']->mailbox_pubkey === $cfg->pubkey);

    // Deleting the retired key is gated too; afterwards messages to it are rejected.
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'delete_key', 'key_id' => $retired[0]->id, 'password' => 'correct horse', 'confirm' => 'nope']);
    check('delete retired key without phrase refused', $cfg->getRetiredKeys()->count() === 1);
    $r = req($kernel, 'POST', "/mailbox/settings/$mid/nostr", ['_token' => csrf_token(), 'action' => 'delete_key', 'key_id' => $retired[0]->id, 'password' => 'correct horse', 'confirm' => 'delete']);
    check('delete retired key with password and phrase works', $cfg->getRetiredKeys()->count() === 0);
    [$wrapGone] = GiftWrap::wrap(['kind' => 14, 'content' => 'x', 'tags' => [['p', $firstPub]]], $custPriv, $firstPub);
    check('message to deleted key rejected', $handler->handleGiftWrap($cfg, $wrapGone, null) === null && NostrEvent::where('wrap_id', $wrapGone['id'])->value('error') === 'not addressed to mailbox');

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

    // Legacy NIP-04 messages are recorded once, never turned into conversations, and reported on the page.
    $legacy = \Modules\Nostr\Services\EventBuilder::finalize(['kind' => 4, 'content' => 'ciphertext?iv=abc', 'tags' => [['p', $cfg->pubkey]]], $custPriv);
    $convCount = Conversation::count();
    check('legacy message recorded', $handler->handleLegacyMessage($cfg, $legacy, 'wss://relay.example.org') === true && Conversation::count() === $convCount);
    check('legacy message not recorded twice', $handler->handleLegacyMessage($cfg, $legacy, 'wss://relay.example.org') === false && NostrEvent::where('wrap_id', $legacy['id'])->count() === 1 && NostrEvent::where('wrap_id', $legacy['id'])->value('kind') == 4);
    $r = req($kernel, 'GET', "/mailbox/settings/$mid/nostr");
    check('page reports legacy messages', strpos($r->getContent(), 'legacy NIP-04 direct messages') !== false && strpos($r->getContent(), 'Check relays') !== false, $r->getStatusCode());

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
    check('outgoing pseudo headers stored', strpos((string) $reply->headers, 'X-Nostr-Relays: ') !== false && strpos((string) $reply->headers, 'failed:') !== false && strpos((string) $reply->headers, 'X-Nostr-To: '.Keys::npub($custPub)) !== false, (string) $reply->headers);
    $out = NostrEvent::where('thread_id', $reply->id)->where('direction', NostrEvent::DIRECTION_OUT)->first();
    check('outgoing event recorded as failed with target relays', $out && $out->status == NostrEvent::STATUS_FAILED && isset($out->getRelays()['ws://127.0.0.1:1']) && isset($out->getRelays()['wss://relay.example.org']), $out ? $out->relays : 'none');
    check('failure was fast', $elapsed < 20, round($elapsed, 1).'s');
    // The first key wrote last (to the retired mailbox key), so the reply goes there, from the retired key.
    check('reply targets the key that wrote last; retired key deleted so current key is used', $out && $out->pubkey === $custPub && $out->mailbox_pubkey === $cfg->pubkey);

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
