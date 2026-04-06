<?php

namespace StitchDB\Laravel;

use RuntimeException;

/**
 * StitchDB client with async query pipelining.
 *
 * Collects queries and executes them in parallel using cURL multi.
 * Sequential reads that need immediate results still work — they flush
 * the pipeline and return the result. But independent queries within
 * the same request execute concurrently.
 */
class StitchDBClient
{
    protected string $url;
    protected string $apiKey;

    /** @var resource|null Persistent cURL multi handle */
    protected $multiHandle = null;

    /** @var array Pending queries to be sent */
    protected array $pipeline = [];

    /** @var array Completed results keyed by ID */
    protected array $results = [];

    protected int $queryId = 0;

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

    /**
     * Queue a query for async execution. Returns a query ID.
     * Call flushAsync() to execute all queued queries in parallel.
     */
    public function queueAsync(string $endpoint, array $body): int
    {
        $id = ++$this->queryId;
        $this->pipeline[] = [
            'id' => $id,
            'endpoint' => $endpoint,
            'body' => $body,
        ];
        return $id;
    }

    /**
     * Execute all queued queries in parallel and return results.
     */
    public function flushAsync(): array
    {
        if (empty($this->pipeline)) return [];

        if ($this->multiHandle === null) {
            $this->multiHandle = curl_multi_init();
        }

        $handles = [];
        $idMap = [];

        foreach ($this->pipeline as $item) {
            $ch = $this->createHandle($item['endpoint'], $item['body']);
            curl_multi_add_handle($this->multiHandle, $ch);
            $handles[] = $ch;
            $idMap[(int)$ch] = $item['id'];
        }

        $this->pipeline = [];

        // Execute all in parallel
        $running = null;
        do {
            curl_multi_exec($this->multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($this->multiHandle, 0.01);
            }
        } while ($running > 0);

        // Collect results
        $results = [];
        foreach ($handles as $ch) {
            $response = curl_multi_getcontent($ch);
            $id = $idMap[(int)$ch];

            $data = json_decode($response, true);
            if ($data === null) {
                $results[$id] = ['error' => 'Invalid response'];
            } elseif (isset($data['error'])) {
                $results[$id] = $data;
            } else {
                $results[$id] = $data;
            }

            curl_multi_remove_handle($this->multiHandle, $ch);
            curl_close($ch);
        }

        return $results;
    }

    /**
     * Send a single request. Uses a one-off cURL handle with HTTP/2 + keep-alive.
     */
    protected function post(string $endpoint, array $body): array
    {
        $ch = $this->createHandle($endpoint, $body);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('StitchDB connection failed: ' . $error);
        }

        curl_close($ch);

        $data = json_decode($response, true);

        if ($data === null) {
            throw new RuntimeException('StitchDB: invalid response');
        }
        if (isset($data['error'])) {
            throw new RuntimeException('StitchDB: ' . $data['error']);
        }

        return $data;
    }

    /**
     * Create a cURL handle configured for HTTP/2, keep-alive, and our auth.
     *
     * @return resource
     */
    protected function createHandle(string $endpoint, array $body)
    {
        $ch = curl_init();
        $json = json_encode($body);

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->url . $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
                'Connection: keep-alive',
            ],
        ]);

        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }

        return $ch;
    }

    public function __destruct()
    {
        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
            $this->multiHandle = null;
        }
    }
}
