<?php

require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use Modules\Nostr\Services\RelayClient;
use Modules\Nostr\Services\Websocket\Client;

class AuthTestSocket extends Client
{
    public $sent = [];
    public $readsBeforeSend = 0;
    public $messages;
    public $acceptAuth = true;
    public $authenticated = false;
    public $requiresAuth;

    public function __construct($requiresAuth = true, $challenge = 'fresh-challenge')
    {
        $this->requiresAuth = $requiresAuth;
        $this->messages = $challenge === null ? [] : [['AUTH', $challenge]];
    }

    public function send($text)
    {
        $data = json_decode($text, true);
        $this->sent[] = $data;
        if ($data[0] === 'AUTH') {
            $this->authenticated = $this->acceptAuth;
            $this->messages[] = ['OK', $data[1]['id'], $this->acceptAuth, ''];
        } elseif ($data[0] === 'EVENT') {
            $ok = !$this->requiresAuth || $this->authenticated;
            $this->messages[] = ['OK', $data[1]['id'], $ok, $ok ? '' : 'auth-required: sign in'];
        } elseif ($data[0] === 'REQ') {
            $this->messages[] = !$this->requiresAuth || $this->authenticated
                ? ['EOSE', $data[1]] : ['CLOSED', $data[1], 'auth-required: sign in'];
        }
    }

    public function receive($timeout)
    {
        if (!$this->sent) {
            $this->readsBeforeSend++;
        }
        if ($this->messages) {
            return RelayClient::encode(array_shift($this->messages));
        }
        usleep((int) (max(0, $timeout) * 1000000));

        return null;
    }

    public function close($code = 1000)
    {
    }

    public function types()
    {
        return array_column($this->sent, 0);
    }
}

class AuthTestRelay extends RelayClient
{
    private $socket;

    public function __construct(AuthTestSocket $socket, $sign = true)
    {
        parent::__construct($sign ? function ($url, $challenge) {
            return ['id' => 'auth-'.$challenge, 'kind' => 22242,
                'tags' => [['relay', $url], ['challenge', $challenge]]];
        } : null);
        $this->socket = $socket;
    }

    protected function connect($url, $timeout)
    {
        return $this->socket;
    }

    public function publishOne($url = 'wss://private.example', $timeout = 3)
    {
        return $this->publishToRelay(['id' => 'message', 'kind' => 1059], $url, $timeout);
    }

    public function fetchOne()
    {
        return $this->fetchFromRelay([['kinds' => [1059]]], 'wss://private.example', 3);
    }
}

function learnRelayAuth($fetch = false)
{
    $socket = new AuthTestSocket();
    $client = new AuthTestRelay($socket);
    if ($fetch) {
        $client->fetchOne();
        check($socket->types() === ['REQ', 'AUTH', 'REQ', 'CLOSE'], 'initial query did not recover');
    } else {
        check($client->publishOne()['ok'], 'initial publish did not recover');
        check($socket->types() === ['EVENT', 'AUTH', 'EVENT'], 'unexpected initial publish sequence');
    }
}

$failures = 0;
runCase('a new publisher remembers authentication and uses the fresh connection challenge', function () {
    learnRelayAuth();
    $socket = new AuthTestSocket(true, 'next-challenge');
    check((new AuthTestRelay($socket))->publishOne()['ok'], 'second publish failed');
    check($socket->types() === ['AUTH', 'EVENT'], 'published before authenticating');
    check($socket->sent[0][1]['tags'] === [['relay', 'wss://private.example'], ['challenge', 'next-challenge']], 'reused stale authentication');
});

runCase('short queries share learned authentication with publishers', function () {
    learnRelayAuth(true);
    $socket = new AuthTestSocket();
    check((new AuthTestRelay($socket))->publishOne()['ok'], 'publish after query failed');
    check($socket->types() === ['AUTH', 'EVENT'], 'query did not teach publisher');
    $socket = new AuthTestSocket();
    (new AuthTestRelay($socket))->fetchOne();
    check($socket->types() === ['AUTH', 'REQ', 'CLOSE'], 'query sent before authenticating');
});

runCase('public relays send immediately and authentication hints are scoped to each URL', function () {
    learnRelayAuth();
    $socket = new AuthTestSocket(false, null);
    check((new AuthTestRelay($socket))->publishOne('wss://public.example')['ok'], 'public publish failed');
    check($socket->types() === ['EVENT'] && $socket->readsBeforeSend === 0, 'waited for public relay authentication');
});

runCase('unsigned discovery does not wait for authentication it cannot perform', function () {
    learnRelayAuth();
    $socket = new AuthTestSocket(false, null);
    (new AuthTestRelay($socket, false))->fetchOne();
    check($socket->types() === ['REQ', 'CLOSE'] && $socket->readsBeforeSend === 0, 'unsigned request waited for AUTH');
});

runCase('a stale hint falls back when a relay stops challenging and is then forgotten', function () {
    learnRelayAuth();
    $socket = new AuthTestSocket(false, null);
    $started = microtime(true);
    check((new AuthTestRelay($socket))->publishOne()['ok'], 'stale hint blocked publishing');
    check(microtime(true) - $started < 2, 'stale hint waited too long');
    check($socket->readsBeforeSend > 0 && $socket->types() === ['EVENT'], 'did not try cached hint');
    $socket = new AuthTestSocket(false, null);
    check((new AuthTestRelay($socket))->publishOne()['ok'], 'subsequent public publish failed');
    check($socket->readsBeforeSend === 0, 'stale hint was retained');
});

runCase('rejected authentication does not publish an unauthenticated event', function () {
    learnRelayAuth();
    $socket = new AuthTestSocket();
    $socket->acceptAuth = false;
    check(!(new AuthTestRelay($socket))->publishOne()['ok'], 'failed AUTH reported success');
    check($socket->types() === ['AUTH'], 'published despite failed AUTH');
});

runCase('waiting for a cached challenge respects the remaining request deadline', function () {
    learnRelayAuth();
    $socket = new AuthTestSocket(false, null);
    $started = microtime(true);
    check(!(new AuthTestRelay($socket))->publishOne('wss://private.example', 0.05)['ok'], 'expired request reported success');
    check(microtime(true) - $started < 0.5, 'authentication exceeded request deadline');
    check($socket->sent === [], 'sent an event after request deadline');
});

exit($failures ? 1 : 0);
