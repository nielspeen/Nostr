<?php

namespace Modules\Nostr\Services\Websocket;

/**
 * Minimal RFC 6455 websocket client on plain PHP streams, with no dependencies.
 *
 * Connecting is non-blocking (tcp connect, TLS, HTTP upgrade), so the listener can
 * drive many relays from one stream_select() loop; open() and receive() wrap the
 * same state machine for synchronous use.
 */
class Client
{
    const STATE_CLOSED = 'closed';
    const STATE_CONNECTING = 'connecting';
    const STATE_TLS = 'tls';
    const STATE_HANDSHAKE = 'handshake';
    const STATE_OPEN = 'open';

    const CONNECT_TIMEOUT = 15;
    const MAX_MESSAGE = 4 * 1024 * 1024;
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    protected $url;
    protected $host;
    protected $port;
    protected $path;
    protected $secure;

    protected $stream = null;
    protected $state = self::STATE_CLOSED;
    protected $error = null;
    protected $connectDeadline = 0;
    protected $handshakeKey = '';
    protected $readBuffer = '';
    protected $writeBuffer = '';
    protected $messages = [];
    protected $fragments = '';
    protected $fragmentOpcode = null;
    protected $closeSent = false;
    protected $lastActivity = 0;

    public function __construct($url)
    {
        $parts = parse_url(trim((string) $url));
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['ws', 'wss']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid websocket URL: '.$url);
        }
        $this->url = $url;
        $this->secure = $scheme === 'wss';
        $this->host = $parts['host'];
        $this->port = (int) ($parts['port'] ?? ($this->secure ? 443 : 80));
        $this->path = ($parts['path'] ?? '') ?: '/';
        if (!empty($parts['query'])) {
            $this->path .= '?'.$parts['query'];
        }
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getState()
    {
        return $this->state;
    }

    public function isOpen()
    {
        return $this->state === self::STATE_OPEN;
    }

    public function isClosed()
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function getError()
    {
        return $this->error;
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function getLastActivity()
    {
        return $this->lastActivity;
    }

    /**
     * Start connecting; returns immediately. Drive it with tick().
     */
    public function connect($timeout = self::CONNECT_TIMEOUT)
    {
        $this->reset();
        $this->connectDeadline = microtime(true) + $timeout;

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client('tcp://'.$this->host.':'.$this->port, $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
        if (!$stream) {
            $this->fail('could not connect: '.($errstr ?: 'error '.$errno));

            return false;
        }
        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->state = self::STATE_CONNECTING;
        $this->lastActivity = time();

        return true;
    }

    /**
     * Blocking connect for synchronous callers.
     */
    public function open($timeout = self::CONNECT_TIMEOUT)
    {
        if (!$this->connect($timeout)) {
            return false;
        }
        while (!$this->isOpen() && !$this->isClosed()) {
            $this->wait(0.2);
            $this->tick();
        }

        return $this->isOpen();
    }

    /**
     * Which stream sets this connection should be selected on.
     */
    public function selectSets(array &$read, array &$write)
    {
        if (!$this->stream) {
            return;
        }
        switch ($this->state) {
            case self::STATE_CONNECTING:
                $write[] = $this->stream;
                break;
            case self::STATE_TLS:
                $read[] = $this->stream;
                $write[] = $this->stream;
                break;
            case self::STATE_HANDSHAKE:
            case self::STATE_OPEN:
                $read[] = $this->stream;
                if ($this->writeBuffer !== '') {
                    $write[] = $this->stream;
                }
                break;
        }
    }

    /**
     * Block until the socket needs attention or the time is up.
     */
    public function wait($seconds)
    {
        $read = [];
        $write = [];
        $this->selectSets($read, $write);
        if (!$read && !$write) {
            usleep((int) ($seconds * 1000000));

            return;
        }
        $except = null;
        $sec = (int) floor($seconds);
        @stream_select($read, $write, $except, $sec, (int) (($seconds - $sec) * 1000000));
    }

    /**
     * Advance the connection: finish connecting, read frames, flush writes.
     */
    public function tick()
    {
        if (!$this->stream) {
            return;
        }

        switch ($this->state) {
            case self::STATE_CONNECTING:
                $read = null;
                $write = [$this->stream];
                $except = null;
                if (@stream_select($read, $write, $except, 0) > 0) {
                    if (@stream_socket_get_name($this->stream, true) === false) {
                        $this->fail('connection refused');
                    } elseif ($this->secure) {
                        $this->state = self::STATE_TLS;
                        $this->tick();
                    } else {
                        $this->sendHandshake();
                    }
                } elseif (microtime(true) > $this->connectDeadline) {
                    $this->fail('connection timed out');
                }
                break;

            case self::STATE_TLS:
                $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($result === true) {
                    $this->sendHandshake();
                } elseif ($result === false) {
                    $this->fail('TLS handshake failed'.self::lastError());
                } elseif (microtime(true) > $this->connectDeadline) {
                    $this->fail('TLS handshake timed out');
                }
                break;

            case self::STATE_HANDSHAKE:
                $this->flushWrite();
                $eof = $this->readInto();
                $pos = strpos($this->readBuffer, "\r\n\r\n");
                if ($pos !== false) {
                    $headers = substr($this->readBuffer, 0, $pos);
                    $this->readBuffer = substr($this->readBuffer, $pos + 4);
                    $this->finishHandshake($headers);
                } elseif ($eof) {
                    $this->fail('connection closed during handshake');
                } elseif (microtime(true) > $this->connectDeadline) {
                    $this->fail('handshake timed out');
                }
                break;

            case self::STATE_OPEN:
                $this->flushWrite();
                $eof = $this->readInto();
                $this->parseFrames();
                if ($eof && $this->state === self::STATE_OPEN) {
                    $this->shutdown('connection closed by relay');
                }
                break;
        }
    }

    /**
     * Text messages received so far (and clears them).
     */
    public function messages()
    {
        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }

    /**
     * Synchronous receive: next text message, or null when the timeout passes.
     *
     * @throws \RuntimeException when the connection is closed
     */
    public function receive($timeout)
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            if ($this->messages) {
                return array_shift($this->messages);
            }
            if (!$this->isOpen()) {
                throw new \RuntimeException($this->error ?: 'connection closed');
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $this->wait(min(0.5, $remaining));
            $this->tick();
        }
    }

    public function send($text)
    {
        if (!$this->isOpen()) {
            throw new \RuntimeException($this->error ?: 'connection is not open');
        }
        $this->sendFrame(0x1, (string) $text);
    }

    public function ping()
    {
        if ($this->isOpen()) {
            $this->sendFrame(0x9, '');
        }
    }

    public function close($code = 1000)
    {
        if ($this->isOpen() && !$this->closeSent) {
            $this->closeSent = true;
            try {
                $this->sendFrame(0x8, pack('n', $code));
            } catch (\Throwable $e) {
                // Closing anyway.
            }
        }
        $this->shutdown(null);
    }

    protected function sendHandshake()
    {
        $this->handshakeKey = base64_encode(random_bytes(16));
        $hostHeader = $this->host.(($this->secure && $this->port !== 443) || (!$this->secure && $this->port !== 80) ? ':'.$this->port : '');
        $this->writeBuffer .= "GET {$this->path} HTTP/1.1\r\n"
            ."Host: {$hostHeader}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$this->handshakeKey}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."User-Agent: FreeScout-Nostr\r\n"
            ."\r\n";
        $this->state = self::STATE_HANDSHAKE;
        $this->flushWrite();
    }

    protected function finishHandshake($headers)
    {
        $lines = explode("\r\n", $headers);
        $status = array_shift($lines);
        if (!preg_match('#^HTTP/1\.1 101#i', $status)) {
            $this->fail('handshake rejected: '.trim($status));

            return;
        }
        $accept = '';
        foreach ($lines as $line) {
            if (stripos($line, 'Sec-WebSocket-Accept:') === 0) {
                $accept = trim(substr($line, 21));
            }
        }
        if ($accept !== base64_encode(sha1($this->handshakeKey.self::GUID, true))) {
            $this->fail('handshake rejected: bad accept key');

            return;
        }
        $this->state = self::STATE_OPEN;
        $this->lastActivity = time();
        // Frames may already have arrived together with the headers.
        $this->parseFrames();
    }

    /**
     * Read whatever is available. Returns true on end of stream.
     */
    protected function readInto()
    {
        for ($i = 0; $i < 64; $i++) {
            $data = @fread($this->stream, 65536);
            if ($data === false) {
                return feof($this->stream);
            }
            if ($data === '') {
                break;
            }
            $this->readBuffer .= $data;
            $this->lastActivity = time();
            if (strlen($this->readBuffer) > self::MAX_MESSAGE + 16) {
                $this->fail('message too large');

                return true;
            }
        }

        return feof($this->stream);
    }

    protected function parseFrames()
    {
        while ($this->state === self::STATE_OPEN) {
            $len = strlen($this->readBuffer);
            if ($len < 2) {
                return;
            }
            $b0 = ord($this->readBuffer[0]);
            $b1 = ord($this->readBuffer[1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0f;
            $masked = ($b1 & 0x80) !== 0;
            $plen = $b1 & 0x7f;
            $offset = 2;
            if ($plen === 126) {
                if ($len < 4) {
                    return;
                }
                $plen = unpack('n', substr($this->readBuffer, 2, 2))[1];
                $offset = 4;
            } elseif ($plen === 127) {
                if ($len < 10) {
                    return;
                }
                $plen = unpack('J', substr($this->readBuffer, 2, 8))[1];
                $offset = 10;
            }
            if ($plen > self::MAX_MESSAGE) {
                $this->fail('message too large');

                return;
            }
            $mask = '';
            if ($masked) {
                if ($len < $offset + 4) {
                    return;
                }
                $mask = substr($this->readBuffer, $offset, 4);
                $offset += 4;
            }
            if ($len < $offset + $plen) {
                return;
            }
            $payload = substr($this->readBuffer, $offset, $plen);
            $this->readBuffer = substr($this->readBuffer, $offset + $plen);
            if ($masked && $plen) {
                $payload ^= str_pad('', $plen, $mask);
            }

            switch ($opcode) {
                case 0x1:
                case 0x2:
                    if ($fin) {
                        if ($opcode === 0x1) {
                            $this->messages[] = $payload;
                        }
                    } else {
                        $this->fragments = $payload;
                        $this->fragmentOpcode = $opcode;
                    }
                    break;
                case 0x0:
                    $this->fragments .= $payload;
                    if (strlen($this->fragments) > self::MAX_MESSAGE) {
                        $this->fail('message too large');

                        return;
                    }
                    if ($fin) {
                        if ($this->fragmentOpcode === 0x1) {
                            $this->messages[] = $this->fragments;
                        }
                        $this->fragments = '';
                        $this->fragmentOpcode = null;
                    }
                    break;
                case 0x8:
                    $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 0;
                    $reason = strlen($payload) > 2 ? substr($payload, 2) : '';
                    if (!$this->closeSent) {
                        $this->closeSent = true;
                        try {
                            $this->sendFrame(0x8, substr($payload, 0, 2));
                        } catch (\Throwable $e) {
                            // Closing anyway.
                        }
                    }
                    $this->shutdown(trim('closed by relay '.$code.' '.$reason));

                    return;
                case 0x9:
                    $this->sendFrame(0xA, $payload);
                    break;
                case 0xA:
                    break;
            }
        }
    }

    protected function sendFrame($opcode, $payload)
    {
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode);
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126).pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $len);
        }
        $mask = random_bytes(4);
        $frame .= $mask;
        if ($len) {
            $frame .= $payload ^ str_pad('', $len, $mask);
        }
        $this->writeBuffer .= $frame;
        $this->flushWrite();
    }

    protected function flushWrite()
    {
        while ($this->writeBuffer !== '' && $this->stream) {
            $written = @fwrite($this->stream, $this->writeBuffer);
            if ($written === false || $written === 0) {
                if (feof($this->stream)) {
                    $this->fail('connection lost while sending');
                }
                break;
            }
            $this->writeBuffer = substr($this->writeBuffer, $written);
            $this->lastActivity = time();
        }
    }

    protected function fail($message)
    {
        $this->shutdown($message);
    }

    protected function shutdown($error)
    {
        if ($this->stream) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->state = self::STATE_CLOSED;
        if ($error !== null) {
            $this->error = $error;
        }
        $this->writeBuffer = '';
    }

    protected function reset()
    {
        if ($this->stream) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->state = self::STATE_CLOSED;
        $this->error = null;
        $this->readBuffer = '';
        $this->writeBuffer = '';
        $this->messages = [];
        $this->fragments = '';
        $this->fragmentOpcode = null;
        $this->closeSent = false;
    }

    protected static function lastError()
    {
        $error = error_get_last();

        return $error && !empty($error['message']) ? ': '.$error['message'] : '';
    }
}
