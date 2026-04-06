<?php

namespace StitchDB\Laravel;

use RuntimeException;

/**
 * StitchDB HTTP client.
 *
 * Uses cURL with HTTP/2 and persistent connections.
 * One TCP connection reused across all queries within a PHP process.
 */
class StitchDBClient
{
    protected string $url;
    protected string $apiKey;

    /** @var resource|null Persistent cURL handle */
    protected $curl = null;

    public function __construct(string $url, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
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
        if ($this->curl === null) {
            $this->curl = curl_init();
            curl_setopt_array($this->curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 60,
                CURLOPT_TCP_KEEPINTVL => 30,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_FRESH_CONNECT => false,
            ]);
            if (defined('CURL_HTTP_VERSION_2_0')) {
                curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
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
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new RuntimeException('StitchDB connection failed: ' . curl_error($this->curl));
        }

        $data = json_decode($response, true);

        if ($data === null) {
            throw new RuntimeException('StitchDB: invalid response from server');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('StitchDB: ' . $data['error']);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('StitchDB: HTTP ' . $httpCode);
        }

        return $data;
    }

    public function __destruct()
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
            $this->curl = null;
        }
    }
}
