<?php

namespace StitchDB\Laravel;

use RuntimeException;

/**
 * StitchDB client.
 *
 * Uses persistent cURL with HTTP/2 connection reuse.
 * Supports collecting queries into a batch and sending them all at once.
 */
class StitchDBClient
{
    protected string $url;
    protected string $apiKey;

    /** @var resource|null Persistent cURL handle */
    protected $ch = null;

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
        $this->apiKey = $apiKey;
    }

    /**
     * Start collecting queries instead of sending them immediately.
     * Call flush() to send all collected queries as one batch.
     */
    public function startCollecting(): void
    {
        $this->collecting = true;
        $this->collected = [];
        $this->batchResults = null;
        $this->batchIndex = 0;
    }

    /**
     * Send all collected queries as ONE HTTP request.
     * Returns array of results in order.
     */
    public function flush(): array
    {
        $this->collecting = false;

        if (empty($this->collected)) {
            return [];
        }

        $queries = $this->collected;
        $this->collected = [];

        $response = $this->post('/v1/batch', ['queries' => $queries]);
        $this->batchResults = $response['results'] ?? [];
        $this->batchIndex = 0;

        return $this->batchResults;
    }

    /**
     * Get the next result from the last batch flush.
     */
    public function nextBatchResult(): ?array
    {
        if ($this->batchResults === null || $this->batchIndex >= count($this->batchResults)) {
            return null;
        }
        $result = $this->batchResults[$this->batchIndex];
        $this->batchIndex++;
        return $result;
    }

    /**
     * Check if we're in collection mode.
     */
    public function isCollecting(): bool
    {
        return $this->collecting;
    }

    /**
     * Get number of collected queries.
     */
    public function collectedCount(): int
    {
        return count($this->collected);
    }

    public function query(string $sql, array $params = []): array
    {
        $body = ['sql' => $sql];
        if (!empty($params)) {
            $body['params'] = array_values($params);
        }

        if ($this->collecting) {
            $this->collected[] = $body;
            // Return empty — real results come from flush()
            return ['results' => [], 'meta' => ['rows_read' => 0, 'rows_written' => 0]];
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

    protected function post(string $endpoint, array $body): array
    {
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
        if ($this->ch !== null) {
            curl_close($this->ch);
            $this->ch = null;
        }
    }
}
