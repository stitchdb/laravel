<?php

namespace StitchDB\Laravel;

use Illuminate\Database\Connectors\ConnectorInterface;

class StitchDBConnector implements ConnectorInterface
{
    /**
     * StitchDB doesn't use PDO — we return null.
     * The Connection class handles HTTP calls directly.
     */
    public function connect(array $config)
    {
        return null;
    }
}
