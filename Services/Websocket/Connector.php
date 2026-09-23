<?php

namespace Modules\Nostr\Services\Websocket;

use GuzzleHttp\Psr7\HttpFactory;
use Ratchet\Client\Connector as PawlConnector;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;

/**
 * Pawl connector using our Negotiator (see there for why).
 */
class Connector extends PawlConnector
{
    public function __construct(?LoopInterface $loop = null, ?ConnectorInterface $connector = null)
    {
        // Deliberately not calling the parent constructor: it instantiates the
        // stock negotiator, which fails with the psr7 version FreeScout ships.
        $this->_loop = $loop ?: Loop::get();
        $this->_connector = $connector ?: new \React\Socket\Connector(['timeout' => 20], $this->_loop);
        $this->_negotiator = new Negotiator(new HttpFactory());
    }
}
