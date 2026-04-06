<?php

namespace StitchDB\Laravel;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\SQLiteGrammar as QueryGrammar;
use StitchDB\Laravel\StitchDBProcessor as Processor;
use StitchDB\Laravel\Schema\StitchDBSchemaGrammar;
use StitchDB\Laravel\Schema\StitchDBBuilder;

class StitchDBConnection extends Connection
{
    protected StitchDBClient $client;
    protected int $lastInsertId = 0;

    /**
     * Transaction buffer — queries are collected here during a transaction
     * and sent as a single atomic batch on commit.
     */
    protected array $transactionBuffer = [];
    protected bool $buffering = false;

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        $url = $config['url'] ?? $config['stitchdb_url'] ?? env('STITCHDB_URL', 'https://db.stitchdb.com');
        $apiKey = $config['api_key'] ?? $config['stitchdb_api_key'] ?? env('STITCHDB_API_KEY', '');

        $this->client = new StitchDBClient($url, $apiKey);

        $this->setReconnector(function ($connection) {
            // HTTP is stateless — nothing to reconnect
        });
    }

    public function getClient(): StitchDBClient
    {
        return $this->client;
    }

    // -------------------------------------------------------------------------
    //  Core query methods — ALL go through the client's batching layer
    // -------------------------------------------------------------------------

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            list($query, $bindings) = self::convertNamedToPositional($query, $bindings);
            $response = $this->client->query($query, $this->prepareBindings($bindings));
            $results = $response['results'] ?? [];
            return array_map(fn($row) => (object) $row, $results);
        });
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $results = $this->select($query, $bindings, $useReadPdo);
        return count($results) > 0 ? $results[0] : null;
    }

    public function selectResultSets($query, $bindings = [], $useReadPdo = true)
    {
        return [$this->select($query, $bindings, $useReadPdo)];
    }

    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        foreach ($this->select($query, $bindings, $useReadPdo) as $result) {
            yield $result;
        }
    }

    public function insert($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            list($query, $bindings) = self::convertNamedToPositional($query, $bindings);
            $prepared = $this->prepareBindings($bindings);

            if ($this->buffering) {
                $this->transactionBuffer[] = ['sql' => $query, 'params' => $prepared];
                return true;
            }

            $response = $this->client->statement($query, $prepared);
            $this->lastInsertId = $response['meta']['last_row_id'] ?? 0;
            return true;
        });
    }

    public function update($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function delete($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            list($query, $bindings) = self::convertNamedToPositional($query, $bindings);
            $prepared = $this->prepareBindings($bindings);

            if ($this->buffering) {
                $this->transactionBuffer[] = ['sql' => $query, 'params' => $prepared];
                return true;
            }

            $response = $this->client->statement($query, $prepared);
            $this->lastInsertId = $response['meta']['last_row_id'] ?? $this->lastInsertId;
            return true;
        });
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            list($query, $bindings) = self::convertNamedToPositional($query, $bindings);
            $prepared = $this->prepareBindings($bindings);

            if ($this->buffering) {
                $this->transactionBuffer[] = ['sql' => $query, 'params' => $prepared];
                return 0;
            }

            $response = $this->client->statement($query, $prepared);
            $this->lastInsertId = $response['meta']['last_row_id'] ?? $this->lastInsertId;
            return $response['meta']['rows_written'] ?? 0;
        });
    }

    public function unprepared($query)
    {
        return $this->run($query, [], function ($query) {
            if ($this->buffering) {
                $this->transactionBuffer[] = ['sql' => $query, 'params' => []];
                return true;
            }
            $this->client->statement($query);
            return true;
        });
    }

    // -------------------------------------------------------------------------
    //  Last insert ID
    // -------------------------------------------------------------------------

    public function getLastInsertId(): int
    {
        return $this->lastInsertId;
    }

    // -------------------------------------------------------------------------
    //  Transactions via batch API
    // -------------------------------------------------------------------------

    public function beginTransaction()
    {
        $this->transactions++;
        if ($this->transactions === 1) {
            $this->buffering = true;
            $this->transactionBuffer = [];
        }
        $this->fireConnectionEvent('beganTransaction');
    }

    public function commit()
    {
        if ($this->transactions === 1) {
            if (!empty($this->transactionBuffer)) {
                $this->client->batch($this->transactionBuffer);
            }
            $this->transactionBuffer = [];
            $this->buffering = false;
        }
        $this->transactions = max(0, $this->transactions - 1);
        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null)
    {
        $toLevel = is_null($toLevel) ? $this->transactions - 1 : $toLevel;
        if ($toLevel < 0) $toLevel = 0;
        if ($toLevel === 0) {
            $this->transactionBuffer = [];
            $this->buffering = false;
        }
        $this->transactions = $toLevel;
        $this->fireConnectionEvent('rollingBack');
    }

    public function transactionLevel()
    {
        return $this->transactions;
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->beginTransaction();
            try {
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (\Throwable $e) {
                $this->rollBack();
                if ($attempt >= $attempts) throw $e;
            }
        }
    }

    // -------------------------------------------------------------------------
    //  Foreign key support
    // -------------------------------------------------------------------------

    public function enableForeignKeyConstraints()
    {
        return $this->statement('PRAGMA foreign_keys = ON');
    }

    public function disableForeignKeyConstraints()
    {
        return $this->statement('PRAGMA foreign_keys = OFF');
    }

    public function pragma($name)
    {
        return $this->select("PRAGMA $name");
    }

    // -------------------------------------------------------------------------
    //  Grammars and processors
    // -------------------------------------------------------------------------

    protected function getDefaultQueryGrammar()
    {
        $ref = new \ReflectionClass(QueryGrammar::class);
        $constructor = $ref->getConstructor();
        if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
            return new QueryGrammar($this);
        }
        $grammar = new QueryGrammar;
        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
        }
        return $grammar;
    }

    protected function getDefaultSchemaGrammar()
    {
        $ref = new \ReflectionClass(StitchDBSchemaGrammar::class);
        $constructor = $ref->getConstructor();
        if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
            return new StitchDBSchemaGrammar($this);
        }
        $grammar = new StitchDBSchemaGrammar;
        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
        }
        return $grammar;
    }

    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }

    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new StitchDBBuilder($this);
    }

    public function getDriverName()
    {
        return 'stitchdb';
    }

    public function getServerVersion(): string
    {
        return '3.45.0';
    }

    public function getPdo()
    {
        return new StitchDBPdoStub($this);
    }

    public function getReadPdo()
    {
        return new StitchDBPdoStub($this);
    }

    // -------------------------------------------------------------------------
    //  Named to positional param conversion
    // -------------------------------------------------------------------------

    public static function convertNamedToPositional(string $sql, array $bindings): array
    {
        if (empty($bindings)) return [$sql, $bindings];
        if (!preg_match('/:[a-zA-Z_]/', $sql)) return [$sql, $bindings];

        $positional = [];
        $newSql = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', function ($match) use ($bindings, &$positional) {
            $key = $match[1];
            $positional[] = $bindings[$key] ?? $bindings[':' . $key] ?? null;
            return '?';
        }, $sql);

        return [$newSql, $positional];
    }

    // -------------------------------------------------------------------------
    //  Binding preparation
    // -------------------------------------------------------------------------

    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();
        foreach ($bindings as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = (int) $value;
            }
        }
        return $bindings;
    }
}
