<?php
// Live test of the nostr:listen daemon: temporarily configures the first mailbox with a throwaway key
// on the real database, starts the daemon, sends a message through a public relay and checks it
// arrives. Cleans up afterwards. Do not run on production. Usage: php Modules/Nostr/Tests/integration_daemon.php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
error_reporting(E_ALL & ~E_DEPRECATED);
use App\Conversation; use App\Customer; use App\CustomerChannel; use App\Thread;
use Modules\Nostr\Entities\CustomerKey; use Modules\Nostr\Entities\NostrEvent; use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\GiftWrap; use Modules\Nostr\Services\Keys; use Modules\Nostr\Services\RelayClient;

$fail = 0;
function check($name, $cond, $extra = '') { global $fail; echo ($cond ? "ok   " : "FAIL ") . $name . ($extra !== '' && !$cond ? " -- $extra" : '') . "\n"; if (!$cond) $fail++; }
$logFile = sys_get_temp_dir().'/nostr-daemon-test.log';
@unlink($logFile);

// The FreeScout cron would start its own listener as soon as the mailbox is enabled and the two
// would compete for the heartbeat. Hold the scheduler's mutex for the duration of the test and
// stop listeners that are already running on this development machine.
$mutex = null;
foreach (\Eventy::filter('schedule', new \Illuminate\Console\Scheduling\Schedule())->events() as $event) {
    if (strpos($event->command, 'nostr:listen') !== false) {
        $mutex = $event->mutexName();
    }
}
if ($mutex) {
    \Cache::put($mutex, true, 30);
}
foreach (\Helper::getRunningProcesses('nostr:listen') as $pid) {
    if ((int) $pid !== getmypid()) {
        posix_kill((int) $pid, SIGTERM);
    }
}
sleep(1);
$mailbox = \App\Mailbox::first();
$before = ['conv' => Conversation::max('id'), 'cust' => Customer::max('id')];
$existingCfg = NostrMailbox::where('mailbox_id', $mailbox->id)->first();
$cfg = NostrMailbox::forMailbox($mailbox->id);
$cfg->setPrivateKey(Keys::generatePrivateKey());
$cfg->setInboxRelays(['wss://nos.lol']);
$cfg->setAnnounceRelays(['wss://nos.lol']);
$cfg->enabled = true;
$cfg->save();
$proc = null;
try {
    // PHP_BINARY so signals reach PHP itself (on some machines "php" is a wrapper script).
    $cmd = 'cd '.escapeshellarg(realpath(__DIR__.'/../../..')).' && exec '.escapeshellarg(PHP_BINARY).' artisan nostr:listen --lifetime=120 > '.escapeshellarg($logFile).' 2>&1';
    $proc = proc_open($cmd, [], $pipes);
    $status = proc_get_status($proc); $pid = $status['pid'];
    echo "daemon pid $pid\n";
    $waitFor = function ($needle, $seconds) use ($logFile) { $t0 = microtime(true); while (microtime(true) - $t0 < $seconds) { if (file_exists($logFile) && strpos(file_get_contents($logFile), $needle) !== false) return true; usleep(500000); } return false; };
    check('daemon connected and caught up', $waitFor('caught up with wss://nos.lol', 25), file_exists($logFile) ? file_get_contents($logFile) : 'no log');

    $custPriv = Keys::generatePrivateKey(); $custPub = Keys::pubkeyFromPrivate($custPriv);
    $msg = 'Daemon test '.bin2hex(random_bytes(4));
    [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => $msg, 'tags' => [['p', $cfg->pubkey]]], $custPriv, $cfg->pubkey);
    $res = (new RelayClient())->publish($wrap, ['wss://nos.lol']);
    check('wrap published', RelayClient::anySucceeded($res), json_encode($res));
    $t0 = microtime(true); $ev = null;
    while (microtime(true) - $t0 < 30) { $ev = NostrEvent::where('wrap_id', $wrap['id'])->first(); if ($ev) break; usleep(500000); }
    check('daemon ingested the message live', $ev && $ev->thread_id && $ev->relay === 'wss://nos.lol', $ev ? json_encode($ev->toArray()) : 'not ingested in 30s');
    if ($ev) { printf("     (latency %.1fs)\n", microtime(true) - $t0); $thread = Thread::find($ev->thread_id); check('thread body', $thread && strpos($thread->body, $msg) !== false); }

    // Heartbeat published for the settings page.
    $status = \Modules\Nostr\Services\ListenerStatus::read();
    $conn = null; foreach ($status['connections'] ?? [] as $c) { if ($c['url'] === 'wss://nos.lol') $conn = $c; }
    check('heartbeat shows the connection', $status && $status['pid'] && $conn && $conn['state'] === 'connected' && $conn['caught_up'] && $conn['events'] >= 1 && \Modules\Nostr\Services\ListenerStatus::forMailbox($cfg->fresh())['state'] === 'running', json_encode($status));

    // Settings change is picked up by the periodic tick.
    $cfg->setInboxRelays(['wss://nos.lol', 'wss://nostr.mom']); $cfg->save();
    check('daemon noticed settings change', $waitFor('settings changed', 40) && $waitFor('connecting to wss://nostr.mom', 10), file_get_contents($logFile));

    // SIGTERM stops it cleanly.
    posix_kill($pid, SIGTERM);
    check('daemon stops on SIGTERM', $waitFor('listener stopped', 10), file_get_contents($logFile));
    $status = \Modules\Nostr\Services\ListenerStatus::read();
    check('heartbeat records the stop', $status && !empty($status['stopped_at']) && $status['stop_reason'] === 'signal' && \Modules\Nostr\Services\ListenerStatus::forMailbox($cfg->fresh())['state'] === 'stopped', json_encode($status));
    $running = proc_get_status($proc)['running'];
    check('process exited', !$running);
} catch (\Throwable $e) {
    echo "EXCEPTION: ".$e->getMessage()."\n"; $fail++;
} finally {
    if ($proc && proc_get_status($proc)['running']) { proc_terminate($proc, 9); }
    echo "--- daemon log ---\n".(file_exists($logFile) ? file_get_contents($logFile) : '')."--- end log ---\n";
    // Cleanup everything the test created.
    foreach (NostrEvent::where('mailbox_id', $mailbox->id)->where('created_at', '>=', now()->subMinutes(10))->get() as $e) { $e->delete(); }
    foreach (Conversation::where('id', '>', $before['conv'] ?: 0)->where('channel', 90)->get() as $c) { Thread::where('conversation_id', $c->id)->delete(); $c->delete(); }
    foreach (Customer::where('id', '>', $before['cust'] ?: 0)->get() as $c) { CustomerKey::where('customer_id', $c->id)->delete(); CustomerChannel::where('customer_id', $c->id)->delete(); $c->delete(); }
    \DB::table('jobs')->where('payload', 'like', '%nostr.%')->delete();
    \Option::set('nostr.listener', null);
    if ($mutex) {
        \Cache::forget($mutex);
    }
    if ($existingCfg) { $existingCfg->save(); NostrMailbox::where('mailbox_id', $mailbox->id)->update($existingCfg->getAttributes()); } else { NostrMailbox::where('mailbox_id', $mailbox->id)->delete(); }
    $mailbox->updateFoldersCounters();
    echo "cleanup done: configs=".NostrMailbox::count()." events=".NostrEvent::count()." keys=".CustomerKey::count()." nostr convs=".Conversation::where('channel', 90)->count()."\n";
}
echo $fail ? "$fail FAILED\n" : "ALL OK\n";
