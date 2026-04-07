<?php

namespace StitchDB\Laravel;

use RuntimeException;

/**
 * StitchDB client.
 *
 * Uses WebSocket for queries (raw PHP sockets, $0.019/M cost).
 * Falls back to persistent cURL with HTTP/2 if WebSocket unavailable.
 */
class StitchDBClient
{
    protected string $url;
    protected string $wsUrl;
    protected string $apiKey;

    /** @var resource|null Persistent cURL handle (HTTP fallback) */
    protected $ch = null;

    /** @var resource|null Raw PHP socket for WebSocket */
    protected $ws = null;

    /** @var bool Whether WebSocket has failed (fall back to HTTP) */
    protected bool $wsFailed = false;

    /** @var int Message ID counter */
    protected int $msgId = 0;

    /** @var bool Whether batch collection mode is active */
    protected bool $collecting = false;

    /** @var array Collected queries during batch mode */
    protected array $collected = [];

    /** @var array|null Results from last batch flush */
    protected ?array $batchResults = null;

    /** @var int Index into batch results */
    protected int $batchIndex = 0;

    public function __construct(string $url, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->wsUrl = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $this->url);
        $this->apiKey = $apiKey;
    }

    // -------------------------------------------------------------------------
    //  WebSocket connection (raw PHP sockets — no dependencies)
    // -------------------------------------------------------------------------

    protected function connectWs(): bool
    {
        if ($this->ws !== null) return true;
        if ($this->wsFailed) return false;

        try {
            $parsed = parse_url($this->wsUrl . '/ws/query?key=' . $this->apiKey);
            $host = $parsed['host'] ?? '';
            $port = $parsed['port'] ?? (($parsed['scheme'] ?? '') === 'wss' ? 443 : 80);
            $path = ($parsed['path'] ?? '/') . '?' . ($parsed['query'] ?? '');
            $useSsl = ($parsed['scheme'] ?? '') === 'wss';

            $address = ($useSsl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
            $context = stream_context_create();

            $socket = @stream_socket_client($address, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                $this->wsFailed = true;
                return false;
            }

            // WebSocket upgrade handshake
            $key = base64_encode(random_bytes(16));
            $headers = "GET {$path} HTTP/1.1\r\n"
                . "Host: {$host}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Authorization: Bearer {$this->apiKey}\r\n"
                . "\r\n";

            fwrite($socket, $headers);

            $response = '';
            while (($line = fgets($socket)) !== false) {
                $response .= $line;
                if ($line === "\r\n") break;
            }

            if (strpos($response, '101') === false) {
                fclose($socket);
                $this->wsFailed = true;
                return false;
            }

            stream_set_timeout($socket, 30);
            $this->ws = $socket;
            return true;
        } catch (\Throwable $e) {
            $this->wsFailed = true;
            return false;
        }
    }

    protected function wsSend(string $action, array $data): array
    {
        $this->msgId++;
        $msg = json_encode(array_merge(['id' => (string) $this->msgId, 'action' => $action], $data));

        // Send WebSocket text frame
        $this->wsWriteFrame($msg);

        // Read response
        $raw = $this->wsReadFrame();
        $resp = json_decode($raw, true);

        if ($resp === null) {
            throw new RuntimeException('StitchDB: invalid WebSocket response');
        }
        if (isset($resp['error'])) {
            throw new RuntimeException('StitchDB: ' . $resp['error']);
        }

        return $resp;
    }

    protected function wsWriteFrame(string $payload): void
    {
        $len = strlen($payload);
        $frame = chr(0x81); // text frame, FIN bit set

        if ($len < 126) {
            $frame .= chr($len | 0x80); // mask bit set
        } elseif ($len < 65536) {
            $frame .= chr(126 | 0x80) . pack('n', $len);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $len);
        }

        // Masking key (required for client-to-server)
        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        $written = @fwrite($this->ws, $frame);
        if ($written === false) {
            $this->closeWs();
            throw new RuntimeException('StitchDB: WebSocket write failed');
        }
    }

    protected function wsReadFrame(): string
    {
        $header = $this->wsRead(2);
        if ($header === false || strlen($header) < 2) {
            $this->closeWs();
            throw new RuntimeException('StitchDB: WebSocket read failed');
        }

        $len = ord($header[1]) & 0x7F;
        if ($len === 126) {
            $ext = $this->wsRead(2);
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->wsRead(8);
            $len = unpack('J', $ext)[1];
        }

        $masked = (ord($header[1]) & 0x80) !== 0;
        $mask = $masked ? $this->wsRead(4) : null;

        $payload = $len > 0 ? $this->wsRead($len) : '';

        if ($masked && $mask) {
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return $payload;
    }

    protected function wsRead(int $bytes)
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = @fread($this->ws, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $data .= $chunk;
        }
        return $data;
    }

    protected function closeWs(): void
    {
        if ($this->ws) {
            @fclose($this->ws);
            $this->ws = null;
        }
        $this->wsFailed = true;
    }

    // -------------------------------------------------------------------------
    //  Batch collection mode
    // -------------------------------------------------------------------------

    public function startCollecting(): void
    {
        $this->collecting = true;
        $this->collected = [];
        $this->batchResults = null;
        $this->batchIndex = 0;
    }

    public function flush(): array
    {
        $this->collecting = false;

        if (empty($this->collected)) {
            return [];
        }

        $queries = $this->collected;
        $this->collected = [];

        $response = $this->send('batch', ['queries' => $queries]);
        $this->batchResults = $response['results'] ?? [];
        $this->batchIndex = 0;

        return $this->batchResults;
    }

    public function nextBatchResult(): ?array
    {
        if ($this->batchResults === null || $this->batchIndex >= count($this->batchResults)) {
            return null;
        }
        $result = $this->batchResults[$this->batchIndex];
        $this->batchIndex++;
        return $result;
    }

    public function isCollecting(): bool
    {
        return $this->collecting;
    }

    public function collectedCount(): int
    {
        return count($this->collected);
    }

    // -------------------------------------------------------------------------
    //  Core send — WebSocket first, HTTP fallback
    // -------------------------------------------------------------------------

    protected function send(string $action, array $data): array
    {
        if (!$this->wsFailed && $this->connectWs()) {
            try {
                return $this->wsSend($action, $data);
            } catch (\Throwable $e) {
                $this->closeWs();
                // If it's a StitchDB error (query error), re-throw — don't fall back
                if (strpos($e->getMessage(), 'StitchDB:') === 0 && strpos($e->getMessage(), 'WebSocket') === false) {
                    throw $e;
                }
                // Connection error — fall through to HTTP
            }
        }
        return $this->httpPost($action, $data);
    }

    public function query(string $sql, array $params = []): array
    {
        $body = ['sql' => $sql];
        if (!empty($params)) {
            $body['params'] = array_values($params);
        }

        if ($this->collecting) {
            $this->collected[] = $body;
            return ['results' => [], 'meta' => ['rows_read' => 0, 'rows_written' => 0]];
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
    //  HTTP fallback — persistent cURL with HTTP/2
    // -------------------------------------------------------------------------

    protected function getHandle()
    {
        if ($this->ch === null) {
            $this->ch = curl_init();
            curl_setopt_array($this->ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 120,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_FRESH_CONNECT => false,
            ]);
            if (defined('CURL_HTTP_VERSION_2_0')) {
                curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
        }
        return $this->ch;
    }

    protected function httpPost(string $action, array $body): array
    {
        if ($action === 'batch') {
            $endpoint = '/v1/batch';
        } elseif ($action === 'exec') {
            $endpoint = '/v1/exec';
        } else {
            $endpoint = '/v1/query';
        }

        $ch = $this->getHandle();
        $json = json_encode($body);

        curl_setopt_array($ch, [
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

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->ch = null;
            throw new RuntimeException('StitchDB connection failed: ' . $error);
        }

        $data = json_decode($response, true);

        if ($data === null) {
            throw new RuntimeException('StitchDB: invalid response');
        }
        if (isset($data['error'])) {
            throw new RuntimeException('StitchDB: ' . $data['error']);
        }

        return $data;
    }

    public function __destruct()
    {
        if ($this->ws !== null) {
            @fclose($this->ws);
            $this->ws = null;
        }
        if ($this->ch !== null) {
            curl_close($this->ch);
            $this->ch = null;
        }
    }
}
