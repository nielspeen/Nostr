<?php

// Integration with CustomApp's callback; fixtures use its in-memory database.
require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Thread;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Services\CustomerLabels;
use Modules\Nostr\Services\Keys;

$failures = 0;
\Schema::table('conversations', function ($table) { $table->integer('channel')->nullable(); });

runCase('callback labels update only existing keys belonging to its customer', function () {
    $owner = customer();
    $a = CustomerKey::link($owner, str_repeat('a', 64), CustomerKey::SOURCE_MANUAL, 'Old label');
    $b = CustomerKey::link(customer('Other'), str_repeat('b', 64), CustomerKey::SOURCE_MANUAL, 'Other label');
    $labels = new CustomerLabels();
    $labels->sync($owner, [
        ['pubkey' => $a->pubkey, 'label' => 'Laptop'],
        ['pubkey' => $b->pubkey, 'label' => 'Wrong owner'],
        ['pubkey' => str_repeat('c', 64), 'label' => 'Unknown key'],
        ['pubkey' => 'invalid', 'label' => 'Invalid'],
        ['pubkey' => $a->pubkey, 'label' => ['invalid']],
        ['pubkey' => $a->pubkey, 'label' => '   '],
    ]);
    check($a->fresh()->label === 'Laptop', 'device label was not synchronized');
    check($a->fresh()->source === CustomerKey::SOURCE_MANUAL, 'key origin changed');
    check($b->fresh()->label === 'Other label' && CustomerKey::count() === 2, 'callback relinked a foreign or unknown key');
    \DB::enableQueryLog();
    \DB::flushQueryLog();
    $labels->sync($owner, [['pubkey' => $a->pubkey, 'label' => 'Laptop']]);
    foreach (\DB::getQueryLog() as $query) {
        check(stripos($query['query'], 'update ') !== 0, 'unchanged label was written again');
    }
    \DB::disableQueryLog();
    $labels->sync($owner, [['pubkey' => $a->pubkey, 'label' => 'Renamed laptop']]);
    check($a->fresh()->label === 'Renamed laptop', 'device rename was ignored');
    $labels->sync($owner, [['pubkey' => $a->pubkey, 'label' => 'device-id-fallback']]);
    check($a->fresh()->label === 'device-id-fallback', 'device ID fallback was ignored');
});

runCase('each incoming message displays its own device with one batched lookup', function () {
    $owner = customer();
    $a = CustomerKey::link($owner, str_repeat('a', 64), CustomerKey::SOURCE_AUTO, 'Laptop');
    $b = CustomerKey::link($owner, str_repeat('b', 64), CustomerKey::SOURCE_AUTO, 'Phone <script>bad()</script>');
    $conversation = conversation($owner);
    $conversation->channel = config('nostr.channel');
    $threads = collect();
    foreach ([$a->pubkey, $b->pubkey, str_repeat('c', 64)] as $index => $pubkey) {
        $thread = new Thread();
        $thread->id = $index + 1;
        $thread->type = Thread::TYPE_CUSTOMER;
        $threads->push($thread);
        \DB::table('nostr_events')->insert([
            'mailbox_id' => 1, 'conversation_id' => $conversation->id, 'thread_id' => $thread->id,
            'direction' => NostrEvent::DIRECTION_IN, 'pubkey' => $pubkey,
        ]);
    }
    $render = function ($thread) use ($threads, $conversation) {
        ob_start();
        \Eventy::action('thread.before_recipients', $thread, null, $threads, $conversation);

        return ob_get_clean();
    };
    \DB::enableQueryLog();
    \DB::flushQueryLog();
    $first = $render($threads[0]);
    $second = $render($threads[1]);
    $unknown = $render($threads[2]);
    $queries = array_filter(\DB::getQueryLog(), function ($query) { return strpos($query['query'], 'nostr_events') !== false; });
    \DB::disableQueryLog();
    check(count($queries) === 1, 'queried the sender once per message');
    check(strpos($first, 'Laptop') !== false && strpos($first, 'Phone') === false, 'first message borrowed the latest sender');
    check(strpos($second, 'Phone &lt;script&gt;') !== false && strpos($second, '<script>') === false, 'label was not escaped');
    check(strpos($unknown, Keys::shortNpub(str_repeat('c', 64))) !== false, 'unknown key did not show an npub');
    $threads[0]->type = Thread::TYPE_MESSAGE;
    check($render($threads[0]) === '', 'agent reply was attributed to a device');
    $threads[0]->type = Thread::TYPE_CUSTOMER;
    $conversation->channel = null;
    check($render($threads[0]) === '', 'email conversation was changed');
});

runCase('one cached callback merges the customer and supplies fresh labels to the page', function () {
    $target = customer('Existing', 'existing@example.com');
    $source = customer();
    $key = CustomerKey::link($source, str_repeat('d', 64));
    $conversation = conversation($source);
    $conversation->channel = config('nostr.channel');
    \DB::table('conversations')->where('id', $conversation->id)->update(['channel' => $conversation->channel]);
    \Option::set('customapp.callback_url', ['1' => 'https://callback.example.test']);
    \Option::set('customapp.cache_ttl', ['1' => '5']);
    $response = ['html' => '<b>Sidebar</b>', 'customer' => [
        'emails' => ['existing@example.com'],
        'nostr_keys' => [['pubkey' => $key->pubkey, 'label' => 'Work laptop']],
    ]];
    $history = [];
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], json_encode($response)),
    ]));
    $stack->push(\GuzzleHttp\Middleware::history($history));
    app()->instance(\GuzzleHttp\Client::class, new \GuzzleHttp\Client(['handler' => $stack]));
    $request = \Illuminate\Http\Request::create('/customapp/content');
    $request->headers->set('referer', 'https://help.example.test/conversation/'.$conversation->id);
    $controller = app(\Modules\CustomApp\Http\Controllers\CustomAppController::class);
    $first = $controller->content($request)->getContent();
    $second = $controller->content($request)->getContent();
    check($key->fresh()->customer_id === $target->id && $key->fresh()->label === 'Work laptop', 'merge lost the label or key');
    check(strpos($first, '<b>Sidebar</b>') !== false && strpos($first, '<template id="nostr-customer-labels">') !== false
        && strpos($first, 'Work laptop') !== false, 'response did not include updated labels');
    check($first === $second && count($history) === 1, 'cache caused another callback or lost labels');
    \Cache::flush();
    $response['customer']['nostr_keys'][0]['label'] = 'Renamed laptop';
    $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200, [], json_encode($response))]);
    app()->instance(\GuzzleHttp\Client::class, new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)]));
    $refreshed = $controller->content($request)->getContent();
    check($key->fresh()->label === 'Renamed laptop' && strpos($refreshed, 'Renamed laptop') !== false, 'fresh callback did not update label');
});

exit($failures ? 1 : 0);
