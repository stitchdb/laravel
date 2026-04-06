<?php

namespace StitchDB\Laravel;

use RuntimeException;

/**
 * StitchDB client using persistent cURL with HTTP/2 multiplexing.
 *
 * Reuses a single TCP+TLS connection for all queries within a PHP process.
 * With HTTP/2, back-to-back requests are multiplexed on the same connection.
 */
class StitchDBClient
{
    protected string $url;
    protected string $apiKey;

    /** @var resource|null Persistent cURL handle - reused across all queries */
    protected $ch = null;

    public function __construct(string $url, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->initHandle();
    }

    protected function initHandle(): void
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,
            CURLOPT_TCP_KEEPINTVL => 60,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
        ]);
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        // Pre-warm: resolve DNS + TLS handshake
        curl_setopt_array($this->ch, [
            CURLOPT_URL => $this->url . '/v1/query',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{"sql":"SELECT 1"}',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Connection: keep-alive',
            ],
        ]);
        curl_exec($this->ch);
    }

    public function query(string $sql, array $params = []): array
    {
        $body = ['sql' => $sql];
        if (!empty($params)) {
            $body['params'] = array_values($params);
        }
        return $this->post('/v1/query', $body);
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

        return $this->post('/v1/batch', ['queries' => $formatted]);
    }

    protected function post(string $endpoint, array $body): array
    {
        if ($this->ch === null) {
            $this->initHandle();
        }

        $json = json_encode($body);

        curl_setopt_array($this->ch, [
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

        $response = curl_exec($this->ch);

        if ($response === false) {
            $error = curl_error($this->ch);
            // Reset handle on error
            curl_close($this->ch);
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
        if ($this->ch !== null) {
            curl_close($this->ch);
            $this->ch = null;
        }
    }
}
