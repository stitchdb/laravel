<?php

namespace StitchDB\Laravel\Schema;

use Illuminate\Database\Schema\SQLiteBuilder;

class StitchDBBuilder extends SQLiteBuilder
{
    public function getTables($schema = null)
    {
        $results = $this->connection->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE '_cf_%' ORDER BY name"
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

    public function getColumns($table, $schema = null)
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

    public function hasTable($table, $schema = null)
    {
        $result = $this->connection->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [$table]
        );

        return $result !== null;
    }

    public function hasColumn($table, $column, $schema = null)
    {
        $columns = $this->getColumns($table);
        foreach ($columns as $col) {
            if (strcasecmp($col['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }

    public function dropAllTables($schema = null)
    {
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $this->connection->statement("DROP TABLE IF EXISTS \"{$table['name']}\"");
        }
    }

    public function getIndexes($table, $schema = null)
    {
        $results = $this->connection->select("PRAGMA index_list(\"{$table}\")");

        return array_map(function ($row) use ($table) {
            $row = (array) $row;
            $columns = $this->connection->select("PRAGMA index_info(\"{$row['name']}\")");
            return [
                'name' => $row['name'],
                'columns' => array_map(function ($c) { return ((array) $c)['name']; }, $columns),
                'type' => null,
                'unique' => (bool) ($row['unique'] ?? false),
                'primary' => false,
            ];
        }, $results);
    }

    public function getForeignKeys($table, $schema = null)
    {
        $results = $this->connection->select("PRAGMA foreign_key_list(\"{$table}\")");

        return array_map(function ($row) {
            $row = (array) $row;
            return [
                'name' => null,
                'columns' => [$row['from']],
                'foreign_schema' => null,
                'foreign_table' => $row['table'],
                'foreign_columns' => [$row['to']],
                'on_update' => $row['on_update'] ?? 'NO ACTION',
                'on_delete' => $row['on_delete'] ?? 'NO ACTION',
            ];
        }, $results);
    }
}
