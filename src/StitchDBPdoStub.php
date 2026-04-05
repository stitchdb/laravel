<?php

namespace StitchDB\Laravel;

/**
 * PDO-compatible wrapper for StitchDB.
 *
 * Laravel's internals call getPdo()->lastInsertId() and other PDO methods.
 * Since StitchDB uses HTTP instead of a PDO connection, this class provides
 * real implementations that delegate to the StitchDB connection.
 *
 * lastInsertId() returns the actual last insert ID from StitchDB's API response.
 * quote() properly escapes strings for SQL.
 * Transactions delegate to the connection's batch transaction system.
 */
class StitchDBPdoStub
{
    protected StitchDBConnection $connection;

    public function __construct(StitchDBConnection $connection)
    {
        $this->connection = $connection;
    }

    public function lastInsertId($name = null)
    {
        return $this->connection->getLastInsertId();
    }

    public function getAttribute($attribute)
    {
        if ($attribute === 5) return '3.45.0'; // PDO::ATTR_SERVER_VERSION
        if ($attribute === 16) return 'stitchdb'; // PDO::ATTR_DRIVER_NAME
        return null;
    }

    public function setAttribute($attribute, $value)
    {
        return true;
    }

    public function quote($string, $type = 2)
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }

    public function inTransaction()
    {
        return $this->connection->transactionLevel() > 0;
    }

    public function beginTransaction()
    {
        $this->connection->beginTransaction();
        return true;
    }

    public function commit()
    {
        $this->connection->commit();
        return true;
    }

    public function rollBack()
    {
        $this->connection->rollBack();
        return true;
    }
}
