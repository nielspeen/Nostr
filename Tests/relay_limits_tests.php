<?php

require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\RelayClient;
use Modules\Nostr\Services\RelayLimits;

class OfflinePublisher extends RelayClient
{
    public $published = [];

    protected function publishToRelay(array $event, $url, $timeout)
    {
        $this->published[] = $url;

        return ['ok' => true, 'message' => ''];
    }
}

function metadata(array $responses, array &$history)
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    app()->instance(Client::class, new Client(['handler' => $stack]));
}

$failures = 0;
runCase('default measures encrypted JSON and a provider override permits larger messages', function () {
    \Option::$cache = [];
    $limits = new RelayLimits();
    $event = ['id' => 'test', 'content' => str_repeat('x', 1100000)];
    check(strpos($limits->error($event, []), '1048576') !== false, 'default ceiling missing');
    \Option::set('nostr.max_message_bytes', 2097152);
    \Option::$cache = [];
    check($limits->error($event, []) === null, 'provider override ignored');
    $size = strlen(GiftWrap::encode(['EVENT', $event]));
    \Option::set('nostr.max_message_bytes', $size);
    \Option::$cache = [];
    check($limits->error($event, []) === null, 'exact wire limit rejected');
    $event['content'] .= 'x';
    check($limits->error($event, []) !== null, 'wire overhead ignored');
});

runCase('each relay applies its own limit and metadata is cached', function () {
    \Option::$cache = [];
    \Option::set('nostr.max_message_bytes', 1048576);
    $history = [];
    metadata([
        new Response(200, [], '{"limitation":{"max_message_length":1000000}}'),
        new Response(200, [], '{"limitation":{"max_message_length":8192}}'),
    ], $history);
    $client = new OfflinePublisher();
    $event = ['id' => 'test', 'content' => str_repeat('x', 10000)];
    $relays = ['wss://one.example', 'wss://two.example'];
    $results = $client->publish($event, $relays);
    check($results[$relays[0]]['ok'] && !$results[$relays[1]]['ok'], 'stricter relay blocked the capable relay');
    check($client->published === [$relays[0]], 'published to the wrong relay');
    check(RelayClient::anySucceeded($results), 'partial delivery was reported as failure');
    check(strpos($results[$relays[1]]['message'], '8192') !== false, 'wrong rejection limit');
    $client->publish($event, $relays);
    check(count($history) === 2, 'refetched fresh metadata');
    check($history[0]['request']->getHeaderLine('Accept') === 'application/nostr+json', 'wrong NIP-11 request');
    check((string) $history[0]['request']->getUri() === 'https://one.example', 'wrong metadata URL');
    $key = 'nostr.relay_limits.'.hash('sha256', $relays[1]);
    $cached = \Cache::get($key);
    $cached['retry_at'] = 0;
    \Cache::put($key, $cached, now()->addHour());
    $history = [];
    metadata([new Response(503)], $history);
    $results = $client->publish($event, array_reverse($relays));
    check($results[$relays[0]]['ok'] && strpos($results[$relays[1]]['message'], '8192') !== false, 'stale limit was lost or affected another relay');
    check($client->published === [$relays[0], $relays[0], $relays[0]], 'relay order changed eligibility');
    check(\Cache::get($key)['retry_at'] > time(), 'failed refresh retried immediately');
});

runCase('a relay without an advertised limit can receive despite a smaller public relay', function () {
    \Option::$cache = [];
    $history = [];
    metadata([
        new Response(200, [], '{"limitation":{"max_message_length":131072}}'),
        new Response(200, [], '{"limitation":{"restricted_writes":true}}'),
        new Response(200, [], '{"limitation":{"max_message_length":1000000}}'),
    ], $history);
    $client = new OfflinePublisher();
    $event = ['id' => 'test', 'content' => str_repeat('x', 200000)];
    $relays = ['wss://small.example', 'wss://private.example', 'wss://large.example'];
    $results = $client->publish($event, array_merge($relays, [$relays[1]]));
    check(!$results[$relays[0]]['ok'] && $results[$relays[1]]['ok'] && $results[$relays[2]]['ok'], 'small relay blocked other destinations');
    check($client->published === [$relays[1], $relays[2]] && count($history) === 3, 'duplicate relay was contacted twice');
});

runCase('all rejected relays report their own limits without publication', function () {
    \Option::$cache = [];
    $history = [];
    metadata([
        new Response(200, [], '{"limitation":{"max_message_length":8192}}'),
        new Response(200, [], '{"limitation":{"max_content_length":9000}}'),
    ], $history);
    $client = new OfflinePublisher();
    $results = $client->publish(['id' => 'test', 'content' => str_repeat('x', 10000)], ['wss://bytes.example', 'wss://content.example']);
    check(!RelayClient::anySucceeded($results) && $client->published === [], 'published despite all relays rejecting size');
    check(strpos($results['wss://bytes.example']['message'], '8192 bytes') !== false, 'wrong wire limit');
    check(strpos($results['wss://content.example']['message'], '9000 characters') !== false, 'another relay overwrote the content limit');
});

runCase('the configured ceiling still applies to every relay', function () {
    \Option::$cache = [];
    \Option::set('nostr.max_message_bytes', 8192);
    $history = [];
    metadata([
        new Response(200, [], '{"limitation":{"max_message_length":1000000}}'),
        new Response(200, [], '{}'),
    ], $history);
    $client = new OfflinePublisher();
    $results = $client->publish(['id' => 'test', 'content' => str_repeat('x', 10000)], ['wss://large.example', 'wss://unknown.example']);
    check(!RelayClient::anySucceeded($results) && $client->published === [], 'bypassed the configured message ceiling');
    foreach ($results as $result) {
        check(strpos($result['message'], '8192 bytes') !== false, 'configured ceiling was ignored');
    }
});

runCase('content limits count characters and invalid limits preserve the default', function () {
    \Option::$cache = [];
    $history = [];
    metadata([
        new Response(200, [], '{"limitation":{"max_content_length":10,"max_message_length":"1"}}'),
        new Response(200, [], '{"limitation":{"max_content_length":0,"max_message_length":-1}}'),
    ], $history);
    $limits = new RelayLimits();
    check($limits->error(['content' => str_repeat('é', 10)], ['wss://content.example']) === null, 'counted bytes as characters');
    check($limits->error(['content' => str_repeat('é', 11)], ['wss://content.example']) !== null, 'content cap ignored');
    check($limits->error(['content' => str_repeat('x', 1000)], ['wss://invalid.example']) === null, 'invalid limits applied');
    check($limits->error(['content' => str_repeat('x', 1100000)], ['wss://invalid.example']) !== null, 'default removed');
});

exit($failures ? 1 : 0);
