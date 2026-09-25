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
    public $published = 0;

    protected function publishToRelay(array $event, $url, $timeout)
    {
        $this->published++;

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
    $event = ['id' => 'test', 'content' => str_repeat('x', 70000)];
    check(strpos($limits->error($event, []), '65536') !== false, 'default ceiling missing');
    \Option::set('nostr.max_message_bytes', 1048576);
    \Option::$cache = [];
    check($limits->error($event, []) === null, 'provider override ignored');
    $size = strlen(GiftWrap::encode(['EVENT', $event]));
    \Option::set('nostr.max_message_bytes', $size);
    \Option::$cache = [];
    check($limits->error($event, []) === null, 'exact wire limit rejected');
    $event['content'] .= 'x';
    check($limits->error($event, []) !== null, 'wire overhead ignored');
});

runCase('lowest advertised limit applies to every relay and metadata is cached', function () {
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
    check(!$results[$relays[0]]['ok'] && !$results[$relays[1]]['ok'], 'skipped stricter relay');
    check($client->published === 0, 'published an oversized event');
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
    check(strpos((new RelayLimits())->error($event, $relays), '8192') !== false, 'forgot smaller limit on failure');
    check(\Cache::get($key)['retry_at'] > time(), 'failed refresh retried immediately');
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
    check($limits->error(['content' => str_repeat('x', 70000)], ['wss://invalid.example']) !== null, 'default removed');
});

exit($failures ? 1 : 0);
