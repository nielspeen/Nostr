<?php

namespace Modules\Nostr\Services\Websocket;

use React\EventLoop\LoopInterface;

/**
 * Other modules (or the core) may ship other versions of ReactPHP that win over the
 * copies vendored here. Paper over the API differences we depend on.
 */
class ReactCompat
{
    /**
     * The global event loop (react/event-loop >= 1.2) or a fresh one.
     */
    public static function loop()
    {
        if (class_exists(\React\EventLoop\Loop::class)) {
            return \React\EventLoop\Loop::get();
        }

        return \React\EventLoop\Factory::create();
    }

    /**
     * react/socket < 1.9 takes (LoopInterface $loop, array $context), 1.9+ takes (array $context, ?LoopInterface $loop).
     */
    public static function socketConnector(array $context, LoopInterface $loop)
    {
        $constructor = (new \ReflectionClass(\React\Socket\Connector::class))->getConstructor();
        $first = $constructor ? ($constructor->getParameters()[0] ?? null) : null;
        $type = $first && $first->getType() instanceof \ReflectionNamedType ? $first->getType()->getName() : '';

        if ($type === 'array') {
            return new \React\Socket\Connector($context, $loop);
        }

        return new \React\Socket\Connector($loop, $context);
    }

    /**
     * Where the classes we rely on come from, for the startup log.
     */
    public static function describe()
    {
        $parts = [];
        foreach ([\React\Socket\Connector::class, \Ratchet\Client\Connector::class, \React\EventLoop\Loop::class] as $class) {
            if (class_exists($class)) {
                $file = (new \ReflectionClass($class))->getFileName();
                $parts[] = $class.' from '.preg_replace('#^.*?(/[^/]+/vendor/[^/]+/[^/]+)/.*$#', '$1', $file);
            }
        }

        return implode('; ', $parts);
    }
}
