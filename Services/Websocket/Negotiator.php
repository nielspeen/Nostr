<?php

namespace Modules\Nostr\Services\Websocket;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Ratchet\RFC6455\Handshake\ResponseVerifier;

/**
 * Client handshake negotiator for Pawl that only uses string header values.
 *
 * Ratchet's own ClientNegotiator passes the websocket version as an integer,
 * which guzzlehttp/psr7 3.x (shipped with FreeScout) rejects.
 */
class Negotiator
{
    /** @var ResponseVerifier */
    protected $verifier;

    /** @var RequestInterface */
    protected $defaultRequest;

    public function __construct(RequestFactoryInterface $requestFactory)
    {
        $this->verifier = new ResponseVerifier();
        $this->defaultRequest = $requestFactory
            ->createRequest('GET', '')
            ->withHeader('Connection', 'Upgrade')
            ->withHeader('Upgrade', 'websocket')
            ->withHeader('Sec-WebSocket-Version', (string) $this->getVersion())
            ->withHeader('User-Agent', 'FreeScout-Nostr');
    }

    public function generateRequest(UriInterface $uri): RequestInterface
    {
        return $this->defaultRequest->withUri($uri)
            ->withHeader('Sec-WebSocket-Key', $this->generateKey());
    }

    public function validateResponse(RequestInterface $request, ResponseInterface $response): bool
    {
        return $this->verifier->verifyAll($request, $response);
    }

    public function generateKey(): string
    {
        return base64_encode(random_bytes(16));
    }

    public function getVersion(): int
    {
        return 13;
    }
}
