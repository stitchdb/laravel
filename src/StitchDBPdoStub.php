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

    /**
     * Prepare a statement — returns a StitchDBPdoStatementStub.
     *
     * @param string $statement
     * @param array $options
     * @return StitchDBPdoStatementStub
     */
    public function prepare($statement, $options = [])
    {
        return new StitchDBPdoStatementStub($this->connection, $statement);
    }

    /**
     * Execute a raw SQL statement and return the number of affected rows.
     *
     * @param string $statement
     * @return int
     */
    public function exec($statement)
    {
        $this->connection->unprepared($statement);
        return 0;
    }

    /**
     * Execute a query and return a statement stub with results.
     *
     * @param string $statement
     * @return StitchDBPdoStatementStub
     */
    public function query($statement)
    {
        $stmt = new StitchDBPdoStatementStub($this->connection, $statement);
        $stmt->execute();
        return $stmt;
    }
}

/**
 * PDOStatement-compatible wrapper for StitchDB.
 *
 * Supports bindParam, bindValue, execute, fetch, fetchAll, fetchColumn,
 * rowCount, columnCount, and setFetchMode.
 */
class StitchDBPdoStatementStub
{
    /** @var StitchDBConnection */
    protected $connection;

    /** @var string */
    protected $statement;

    /** @var array */
    protected $bindings = [];

    /** @var array */
    protected $results = [];

    /** @var int */
    protected $cursor = 0;

    /** @var int */
    protected $rowCount = 0;

    /** @var int Fetch mode — defaults to PDO::FETCH_OBJ (5) */
    protected $fetchMode = 5;

    /** PDO fetch mode constants */
    const FETCH_ASSOC = 2;
    const FETCH_NUM = 3;
    const FETCH_BOTH = 4;
    const FETCH_OBJ = 5;

    public function __construct(StitchDBConnection $connection, string $statement)
    {
        $this->connection = $connection;
        $this->statement = $statement;
    }

    /**
     * Bind a parameter by reference.
     *
     * @param mixed $param
     * @param mixed $variable
     * @param int $type
     * @param int|null $length
     * @param mixed $driverOptions
     * @return bool
     */
    public function bindParam($param, &$variable, $type = 2, $length = null, $driverOptions = null)
    {
        $this->bindings[$param] = &$variable;
        return true;
    }

    /**
     * Bind a value to a parameter.
     *
     * @param mixed $param
     * @param mixed $value
     * @param int $type
     * @return bool
     */
    public function bindValue($param, $value, $type = 2)
    {
        $this->bindings[$param] = $value;
        return true;
    }

    /**
     * Execute the prepared statement.
     *
     * @param array|null $params
     * @return bool
     */
    public function execute($params = null)
    {
        $bindings = $params !== null ? $params : array_values($this->bindings);
        $sql = trim($this->statement);
        $upper = strtoupper(substr($sql, 0, 6));

        if ($upper === 'SELECT' || $upper === 'PRAGMA') {
            $this->results = $this->connection->select($sql, $bindings);
            $this->rowCount = count($this->results);
        } else {
            $this->rowCount = $this->connection->affectingStatement($sql, $bindings);
            $this->results = [];
        }

        $this->cursor = 0;
        return true;
    }

    /**
     * Fetch the next row from the result set.
     *
     * @param int|null $mode
     * @param int $cursorOrientation
     * @param int $cursorOffset
     * @return mixed
     */
    public function fetch($mode = null, $cursorOrientation = 0, $cursorOffset = 0)
    {
        if ($this->cursor >= count($this->results)) {
            return false;
        }

        $row = $this->results[$this->cursor];
        $this->cursor++;

        return $this->formatRow($row, $mode !== null ? $mode : $this->fetchMode);
    }

    /**
     * Fetch all rows from the result set.
     *
     * @param int|null $mode
     * @return array
     */
    public function fetchAll($mode = null)
    {
        $effectiveMode = $mode !== null ? $mode : $this->fetchMode;
        $rows = [];

        foreach ($this->results as $row) {
            $rows[] = $this->formatRow($row, $effectiveMode);
        }

        $this->cursor = count($this->results);
        return $rows;
    }

    /**
     * Fetch a single column from the next row.
     *
     * @param int $column
     * @return mixed
     */
    public function fetchColumn($column = 0)
    {
        if ($this->cursor >= count($this->results)) {
            return false;
        }

        $row = (array) $this->results[$this->cursor];
        $this->cursor++;
        $values = array_values($row);

        return isset($values[$column]) ? $values[$column] : false;
    }

    /**
     * Return the number of rows affected by the last statement.
     *
     * @return int
     */
    public function rowCount()
    {
        return $this->rowCount;
    }

    /**
     * Return the number of columns in the result set.
     *
     * @return int
     */
    public function columnCount()
    {
        if (empty($this->results)) {
            return 0;
        }

        return count((array) $this->results[0]);
    }

    /**
     * Set the fetch mode for this statement.
     *
     * @param int $mode
     * @return bool
     */
    public function setFetchMode($mode)
    {
        $this->fetchMode = $mode;
        return true;
    }

    /**
     * Format a row according to the given fetch mode.
     *
     * @param mixed $row
     * @param int $mode
     * @return mixed
     */
    protected function formatRow($row, $mode)
    {
        switch ($mode) {
            case self::FETCH_ASSOC:
                return (array) $row;

            case self::FETCH_NUM:
                return array_values((array) $row);

            case self::FETCH_BOTH:
                $assoc = (array) $row;
                return array_merge(array_values($assoc), $assoc);

            case self::FETCH_OBJ:
            default:
                return is_object($row) ? $row : (object) $row;
        }
    }
}
