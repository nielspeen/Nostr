<?php
// End-to-end test against public relays with throwaway keys: announce, deliver a customer message,
// ingest it, reply, read the reply back as the customer. Database changes are rolled back; the
// throwaway events stay on the relays. Usage: php Modules/Nostr/Tests/integration_network.php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Thread;
use App\User;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\Announcer;
use Modules\Nostr\Services\EventBuilder;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\IncomingMessageHandler;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\Listener;
use Modules\Nostr\Services\OutgoingMessageSender;
use Modules\Nostr\Services\RelayClient;
use Modules\Nostr\Services\RelayDiscovery;

$fail = 0;
function check($name, $cond, $extra = '') { global $fail; echo ($cond ? "ok   " : "FAIL ") . $name . ($extra !== '' && !$cond ? " -- $extra" : '') . "\n"; if (!$cond) $fail++; }
$log = function ($m) { echo "     > $m\n"; };
$t = function ($label, $t0) { printf("     (%s: %.1fs)\n", $label, microtime(true) - $t0); };

\DB::beginTransaction();
try {
    $admin = User::where('role', User::ROLE_ADMIN)->first();
    \Auth::login($admin);
    $mailbox = \App\Mailbox::first();

    $cfg = NostrMailbox::forMailbox($mailbox->id);
    $cfg->setPrivateKey(Keys::generatePrivateKey());
    $cfg->setInboxRelays(['wss://relay.damus.io', 'wss://nos.lol']);
    $cfg->setAnnounceRelays(['wss://relay.damus.io', 'wss://nos.lol', 'wss://relay.primal.net']);
    $cfg->profile_name = 'FreeScout test desk '.substr($cfg->pubkey, 0, 6);
    $cfg->profile_about = 'Temporary key used by an automated test. Ignore.';
    $cfg->enabled = true;
    $cfg->save();
    echo "mailbox npub: ".$cfg->getNpub()."\n";

    // 1. Announce.
    $t0 = microtime(true);
    $results = (new Announcer($log))->announce($cfg);
    $t('announce', $t0);
    $accepted = []; foreach ($results as $kind => $relays) { $accepted[$kind] = count(array_filter($relays, function ($r) { return !empty($r['ok']); })); }
    check('announce accepted by relays (kinds 10050, 10002, 0)', ($accepted[10050] ?? 0) > 0 && ($accepted[10002] ?? 0) > 0 && ($accepted[0] ?? 0) > 0, json_encode($accepted));

    // 2. Discovery of our own announcements from another angle.
    $t0 = microtime(true);
    $discovery = new RelayDiscovery(new RelayClient(null, $log));
    $dm = $discovery->dmRelays($cfg->pubkey, ['wss://relay.primal.net', 'wss://nos.lol']);
    $profile = $discovery->profile($cfg->pubkey, ['wss://relay.damus.io']);
    $t('discovery', $t0);
    check('kind 10050 readable from relays', $dm === ['wss://relay.damus.io', 'wss://nos.lol'], json_encode($dm));
    check('kind 0 readable from relays', ($profile['name'] ?? '') === $cfg->profile_name, json_encode($profile));

    // 3. Customer publishes a DM relay list and a message to the mailbox.
    $custPriv = Keys::generatePrivateKey(); $custPub = Keys::pubkeyFromPrivate($custPriv);
    $custClient = new RelayClient(RelayClient::authSignerForKey($custPriv), $log);
    $custDm = EventBuilder::finalize(['kind' => 10050, 'tags' => [['relay', 'wss://nos.lol']], 'content' => ''], $custPriv);
    $res = $custClient->publish($custDm, ['wss://relay.damus.io', 'wss://nos.lol']);
    check('customer 10050 published', RelayClient::anySucceeded($res), json_encode($res));
    $msg = 'Test message '.bin2hex(random_bytes(4)).' from the automated test';
    [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => $msg, 'tags' => [['p', $cfg->pubkey, 'wss://relay.damus.io'], ['subject', 'Automated test']]], $custPriv, $cfg->pubkey);
    $t0 = microtime(true);
    $res = $custClient->publish($wrap, $cfg->getInboxRelays());
    $t('publish wrap', $t0);
    check('customer wrap accepted by inbox relays', RelayClient::anySucceeded($res), json_encode($res));

    // 4. FreeScout fetches it (once mode).
    sleep(2);
    $t0 = microtime(true);
    $listener = new Listener(new IncomingMessageHandler($log), 60, $log);
    $count = $listener->runOnce();
    $t('runOnce', $t0);
    $in = NostrEvent::where('wrap_id', $wrap['id'])->first();
    check('message ingested into a conversation', $count === 1 && $in && $in->thread_id, json_encode($in ? $in->toArray() : null));
    $thread = $in ? Thread::find($in->thread_id) : null;
    $conv = $thread ? $thread->conversation : null;
    check('thread body matches', $thread && strpos($thread->body, $msg) !== false && $conv->subject === 'Automated test');
    check('second run finds nothing new', $listener->runOnce() === 0);

    // 5. Agent replies.
    $replyText = 'Reply '.bin2hex(random_bytes(4)).' from FreeScout';
    $reply = Thread::createExtended(['type' => Thread::TYPE_MESSAGE, 'body' => '<div>'.$replyText.'<br>Second line</div>', 'created_by_user_id' => $admin->id], $conv, $conv->customer);
    $t0 = microtime(true);
    $sender = new OutgoingMessageSender($log);
    $ok = $sender->handleSendReply($conv->fresh(), collect([$reply]));
    $t('send reply', $t0);
    $out = NostrEvent::where('thread_id', $reply->id)->where('direction', NostrEvent::DIRECTION_OUT)->first();
    check('reply delivered', $ok && $out && $out->status == NostrEvent::STATUS_OK && $reply->fresh()->send_status == \App\SendLog::STATUS_ACCEPTED, json_encode($out ? $out->relays : null));
    check("reply went to the customer's DM relay", $out && isset($out->getRelays()['wss://nos.lol']) && !empty($out->getRelays()['wss://nos.lol']['ok']), $out ? $out->relays : '');

    // 6. Customer reads the reply.
    sleep(2);
    $t0 = microtime(true);
    $wraps = $custClient->fetch([['kinds' => [1059], '#p' => [$custPub], 'since' => time() - 3 * 86400]], ['wss://nos.lol'], 8, false);
    $t('customer fetch', $t0);
    $found = null;
    foreach ($wraps as $w) {
        try { $u = GiftWrap::unwrap($w, $custPriv, $custPub); } catch (\Throwable $e) { continue; }
        if (strpos($u['rumor']['content'], $replyText) !== false) { $found = $u['rumor']; break; }
    }
    check('customer can decrypt the reply', $found !== null && $found['pubkey'] === $cfg->pubkey, count($wraps).' wraps');
    check('reply threads to the customer message', $found && EventBuilder::firstTag($found, 'e') === $in->rumor_id && EventBuilder::firstTag($found, 'subject') === 'Automated test' && strpos($found['content'], "Second line") !== false, $found ? json_encode($found['tags']) : '');

    // 7. Auto reply path end to end.
    $cfg->auto_reply_enabled = true; $cfg->auto_reply_text = 'Auto '.bin2hex(random_bytes(3)); $cfg->save();
    $t0 = microtime(true);
    $ok = $sender->sendAutoReply($conv->id, $cfg->id, $custPub);
    $t('auto reply', $t0);
    $li = Thread::where('conversation_id', $conv->id)->where('type', Thread::TYPE_LINEITEM)->where('action_type', OutgoingMessageSender::ACTION_TYPE_AUTO_REPLY)->first();
    check('auto reply delivered with line item', $ok && $li && $li->body === $cfg->auto_reply_text);
    if ($li) { check('line item text', strpos($li->getActionText(), 'Nostr auto reply') !== false && $li->getActionPerson() === 'System', $li->getActionPerson().' | '.$li->getActionText()); }

    // 8. Profile fetch background action for the auto-created customer (customer has no kind 0: name stays).
    \Modules\Nostr\Providers\NostrServiceProvider::fetchProfile($conv->customer_id, $custPub, $cfg->id);
    $key = \Modules\Nostr\Entities\CustomerKey::byPubkey($custPub);
    check('customer DM relays cached by profile fetch', $key && $key->getDmRelays() === ['wss://nos.lol'] && $key->dmRelaysAreFresh(), json_encode($key ? $key->getDmRelays() : null));
} catch (\Throwable $e) {
    echo "EXCEPTION: ".get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n"; $fail++;
} finally {
    \DB::rollBack();
}
echo $fail ? "$fail FAILED\n" : "ALL OK\n";
