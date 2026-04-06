<?php

namespace StitchDB\Laravel;

use RuntimeException;

/**
 * StitchDB client with WebSocket-first protocol.
 *
 * Opens ONE WebSocket per PHP request. All queries flow as messages
 * through that single connection. 1 Worker invocation per page view,
 * not per query.
 *
 * Falls back to HTTP/2 if WebSocket fails.
 */
class StitchDBClient
{
    protected string $url;
    protected string $wsUrl;
    protected string $apiKey;

    /** @var resource|null WebSocket connection */
    protected $socket = null;
    protected bool $wsConnected = false;
    protected bool $wsFailed = false;

    /** @var resource|null Fallback cURL handle */
    protected $curl = null;

    protected int $msgId = 0;

    public function __construct(string $url, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->wsUrl = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $this->url);
        $this->apiKey = $apiKey;
    }

    public function query(string $sql, array $params = []): array
    {
        $body = ['sql' => $sql];
        if (!empty($params)) {
            $body['params'] = array_values($params);
        }

        return $this->send('query', $body);
    }

    public function statement(string $sql, array $params = []): array
    {
        return $this->query($sql, $params);
    }

    public function batch(array $queries): array
    {
        $formatted = array_map(function ($q) {
            $item = ['sql' => $q['sql']];
            if (!empty($q['params'])) {
                $item['params'] = array_values($q['params']);
            }
            return $item;
        }, $queries);

        return $this->send('batch', ['queries' => $formatted]);
    }

    // -------------------------------------------------------------------------
    //  Transport layer — WebSocket first, HTTP fallback
    // -------------------------------------------------------------------------

    protected function send(string $action, array $data): array
    {
        // Try WebSocket
        if (!$this->wsFailed) {
            try {
                return $this->wsSend($action, $data);
            } catch (\Throwable $e) {
                // If it's a query error (not connection error), throw it
                if ($this->wsConnected && strpos($e->getMessage(), 'StitchDB:') === 0) {
                    throw $e;
                }
                // Connection failed — fall back to HTTP
                $this->wsFailed = true;
                $this->wsDisconnect();
            }
        }

        // HTTP fallback
        return $this->httpSend($action, $data);
    }

    // -------------------------------------------------------------------------
    //  WebSocket transport
    // -------------------------------------------------------------------------

    protected function wsConnect(): void
    {
        if ($this->wsConnected) return;

        $url = $this->wsUrl . '/ws/query?key=' . $this->apiKey;
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $port = ($parsed['scheme'] ?? 'wss') === 'wss' ? 443 : 80;
        $path = ($parsed['path'] ?? '/') . '?' . ($parsed['query'] ?? '');
        $useSsl = ($parsed['scheme'] ?? 'wss') === 'wss';

        $context = stream_context_create();
        if ($useSsl) {
            stream_context_set_option($context, 'ssl', 'verify_peer', true);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', true);
        }

        $prefix = $useSsl ? 'ssl://' : 'tcp://';
        $this->socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$this->socket) {
            throw new RuntimeException("WebSocket connect failed: $errstr ($errno)");
        }

        // WebSocket handshake
        $key = base64_encode(random_bytes(16));
        $headers = "GET $path HTTP/1.1\r\n"
            . "Host: $host\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($this->socket, $headers);

        // Read handshake response
        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if (trim($line) === '') break;
        }

        if (strpos($response, '101') === false) {
            fclose($this->socket);
            $this->socket = null;
            throw new RuntimeException('WebSocket handshake failed');
        }

        stream_set_timeout($this->socket, 30);
        $this->wsConnected = true;
    }

    protected function wsSend(string $action, array $data): array
    {
        $this->wsConnect();

        $id = (string)(++$this->msgId);
        $msg = json_encode(array_merge(['id' => $id, 'action' => $action], $data));

        $this->wsWriteFrame($msg);
        $response = $this->wsReadFrame();

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new RuntimeException('StitchDB: invalid response');
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException('StitchDB: ' . $decoded['error']);
        }

        return $decoded;
    }

    protected function wsWriteFrame(string $data): void
    {
        $length = strlen($data);
        $frame = chr(0x81); // text frame, final

        if ($length < 126) {
            $frame .= chr($length | 0x80);
        } elseif ($length < 65536) {
            $frame .= chr(126 | 0x80) . pack('n', $length);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $length);
        }

        // Masking key (required for client → server)
        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $data[$i] ^ $mask[$i % 4];
        }

        fwrite($this->socket, $frame);
    }

    protected function wsReadFrame(): string
    {
        $header = fread($this->socket, 2);
        if ($header === false || strlen($header) < 2) {
            throw new RuntimeException('WebSocket read failed');
        }

        $length = ord($header[1]) & 0x7F;

        if ($length === 126) {
            $ext = fread($this->socket, 2);
            $length = unpack('n', $ext)[1];
        } elseif ($length === 127) {
            $ext = fread($this->socket, 8);
            $length = unpack('J', $ext)[1];
        }

        // Server frames are not masked
        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($this->socket, min($remaining, 8192));
            if ($chunk === false) break;
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    protected function wsDisconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->wsConnected = false;
    }

    // -------------------------------------------------------------------------
    //  HTTP fallback transport
    // -------------------------------------------------------------------------

    protected function httpSend(string $action, array $data): array
    {
        if ($this->curl === null) {
            $this->curl = curl_init();
            curl_setopt_array($this->curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_FRESH_CONNECT => false,
            ]);
            if (defined('CURL_HTTP_VERSION_2_0')) {
                curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
        }

        $endpoint = '/v1/query';
        $body = $data;
        if ($action === 'batch') {
            $endpoint = '/v1/batch';
        } elseif ($action === 'exec') {
            $endpoint = '/v1/exec';
        }

        $json = json_encode($body);

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->url . $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
                'Connection: keep-alive',
            ],
        ]);

        $response = curl_exec($this->curl);

        if ($response === false) {
            throw new RuntimeException('StitchDB connection failed: ' . curl_error($this->curl));
        }

        $result = json_decode($response, true);

        if ($result === null) {
            throw new RuntimeException('StitchDB: invalid response');
        }
        if (isset($result['error'])) {
            throw new RuntimeException('StitchDB: ' . $result['error']);
        }

        return $result;
    }

    public function __destruct()
    {
        $this->wsDisconnect();
        if ($this->curl !== null) {
            curl_close($this->curl);
            $this->curl = null;
        }
    }
}
