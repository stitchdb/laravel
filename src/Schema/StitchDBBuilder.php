<?php

namespace StitchDB\Laravel\Schema;

use Illuminate\Database\Schema\SQLiteBuilder;

class StitchDBBuilder extends SQLiteBuilder
{
    /**
     * Get the tables for the database.
     */
    public function getTables()
    {
        $results = $this->connection->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return array_map(function ($row) {
            $row = (array) $row;
            return [
                'name' => $row['name'],
                'schema' => null,
                'size' => null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ];
        }, $results);
    }

    /**
     * Get the columns for a given table.
     */
    public function getColumns($table)
    {
        $results = $this->connection->select("PRAGMA table_info(\"{$table}\")");

        return array_map(function ($row) {
            $row = (array) $row;
            return [
                'name' => $row['name'],
                'type_name' => $row['type'] ?? 'TEXT',
                'type' => $row['type'] ?? 'TEXT',
                'collation' => null,
                'nullable' => !($row['notnull'] ?? false),
                'default' => $row['dflt_value'] ?? null,
                'auto_increment' => ($row['pk'] ?? 0) && strtoupper($row['type'] ?? '') === 'INTEGER',
                'comment' => null,
                'generation' => null,
            ];
        }, $results);
    }

    /**
     * Determine if the given table exists.
     */
    public function hasTable($table)
    {
        $result = $this->connection->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [$table]
        );

        return $result !== null;
    }

    /**
     * Drop all tables from the database.
     */
    public function dropAllTables()
    {
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $this->connection->statement("DROP TABLE IF EXISTS \"{$table['name']}\"");
        }
    }
}
