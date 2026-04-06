<?php

namespace StitchDB\Laravel;

use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;

class StitchDBConnector implements ConnectorInterface
{
    /**
     * StitchDB doesn't use PDO — we return null.
     * The Connection class handles HTTP calls directly.
     *
     * Validates that the required URL and API key are present in the config.
     *
     * @param array $config
     * @return null
     *
     * @throws InvalidArgumentException
     */
    public function connect(array $config)
    {
        $url = $config['url'] ?? $config['stitchdb_url'] ?? null;

        if (empty($url)) {
            throw new InvalidArgumentException(
                'StitchDB connection requires a "url" or "stitchdb_url" configuration value. '
                . 'Set the STITCHDB_URL environment variable or add it to your database config.'
            );
        }

        $apiKey = $config['api_key'] ?? $config['stitchdb_api_key'] ?? null;

        if (empty($apiKey)) {
            throw new InvalidArgumentException(
                'StitchDB connection requires an "api_key" or "stitchdb_api_key" configuration value. '
                . 'Set the STITCHDB_API_KEY environment variable or add it to your database config.'
            );
        }

        return null;
    }
}
