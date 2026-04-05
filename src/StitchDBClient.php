<?php

namespace StitchDB\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class StitchDBClient
{
    protected Client $http;
    protected string $url;
    protected string $apiKey;

    public function __construct(string $url, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->http = new Client([
            'base_uri' => $this->url,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->send('/v1/query', $sql, $params);
    }

    public function statement(string $sql, array $params = []): array
    {
        return $this->send('/v1/query', $sql, $params);
    }

    public function batch(array $queries): array
    {
        $body = ['queries' => array_map(function ($q) {
            $item = ['sql' => $q['sql']];
            if (!empty($q['params'])) {
                $item['params'] = array_values($q['params']);
            }
            return $item;
        }, $queries)];

        try {
            $response = $this->http->post('/v1/batch', [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new RuntimeException('StitchDB: ' . $data['error']);
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new RuntimeException('StitchDB batch failed: ' . $e->getMessage());
        }
    }

    protected function send(string $endpoint, string $sql, array $params = []): array
    {
        $body = ['sql' => $sql];
        if (!empty($params)) {
            $body['params'] = array_values($params);
        }

        try {
            $response = $this->http->post($endpoint, [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new RuntimeException('StitchDB: ' . $data['error']);
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new RuntimeException('StitchDB request failed: ' . $e->getMessage());
        }
    }
}
