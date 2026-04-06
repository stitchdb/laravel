<?php

namespace StitchDB\Laravel\Schema;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Connection;

class StitchDBSchemaGrammar extends SQLiteGrammar
{
    // -------------------------------------------------------------------------
    //  Compile methods
    // -------------------------------------------------------------------------

    /**
     * Compile a CREATE TABLE command with inline foreign keys.
     *
     * SQLite requires foreign keys to be defined inline in CREATE TABLE
     * (ALTER TABLE ADD CONSTRAINT is not supported).
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @param Connection $connection
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $columns = $this->getColumns($blueprint);

        $sql = 'create table ' . $this->wrapTable($blueprint) . ' (' . implode(', ', $columns);

        // Add inline foreign key constraints
        $foreignKeys = $this->addForeignKeys($blueprint);
        if ($foreignKeys !== '') {
            $sql .= ', ' . $foreignKeys;
        }

        $sql .= ')';

        return $sql;
    }

    /**
     * Generate inline foreign key constraint SQL for CREATE TABLE.
     *
     * @param Blueprint $blueprint
     * @return string
     */
    protected function addForeignKeys(Blueprint $blueprint)
    {
        $foreignKeys = [];

        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'foreign') {
                $fk = $this->compileForeignInline($command);
                if ($fk !== '') {
                    $foreignKeys[] = $fk;
                }
            }
        }

        return implode(', ', $foreignKeys);
    }

    /**
     * Compile a single inline foreign key constraint.
     *
     * @param Fluent $command
     * @return string
     */
    protected function compileForeignInline(Fluent $command)
    {
        $columns = $command->columns;
        $on = $command->on;
        $references = $command->references;

        if (empty($columns) || empty($on) || empty($references)) {
            return '';
        }

        $sql = 'foreign key(' . $this->columnize($columns) . ') references '
            . $this->wrapTable($on) . '(' . $this->columnize((array) $references) . ')';

        if (!empty($command->onDelete)) {
            $sql .= ' on delete ' . $command->onDelete;
        }

        if (!empty($command->onUpdate)) {
            $sql .= ' on update ' . $command->onUpdate;
        }

        return $sql;
    }

    /**
     * Compile an ALTER TABLE ADD COLUMN command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @param Connection $connection
     * @return array
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $columns = $this->getColumns($blueprint);

        $statements = [];
        foreach ($columns as $column) {
            $statements[] = 'alter table ' . $this->wrapTable($blueprint) . ' add column ' . $column;
        }

        return $statements;
    }

    /**
     * Compile a DROP TABLE command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a DROP TABLE IF EXISTS command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a RENAME TABLE command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table ' . $this->wrapTable($blueprint) . ' rename to ' . $this->wrapTable($command->to);
    }

    /**
     * Compile the command to enable foreign key constraints.
     *
     * @return string
     */
    public function compileEnableForeignKeyConstraints()
    {
        return 'PRAGMA foreign_keys = ON';
    }

    /**
     * Compile the command to disable foreign key constraints.
     *
     * @return string
     */
    public function compileDisableForeignKeyConstraints()
    {
        return 'PRAGMA foreign_keys = OFF';
    }

    /**
     * Ensure foreign key constraints include ON DELETE and ON UPDATE actions.
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        $sql = parent::compileForeign($blueprint, $command);

        // Ensure ON DELETE CASCADE/SET NULL/etc is included
        if (!empty($command->onDelete) && stripos($sql, 'on delete') === false) {
            $sql .= ' on delete ' . $command->onDelete;
        }

        if (!empty($command->onUpdate) && stripos($sql, 'on update') === false) {
            $sql .= ' on update ' . $command->onUpdate;
        }

        return $sql;
    }

    // -------------------------------------------------------------------------
    //  Type mappings — all SQLite-compatible
    // -------------------------------------------------------------------------

    /**
     * Map BIGINT to INTEGER so auto-increment works.
     * SQLite only auto-increments on INTEGER PRIMARY KEY, not BIGINT.
     */
    protected function typeId(Fluent $column)
    {
        return 'integer';
    }

    protected function typeBigInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeSmallInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeTinyInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeMediumInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeUnsignedBigInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeUnsignedInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeUnsignedSmallInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeUnsignedTinyInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeUnsignedMediumInteger(Fluent $column)
    {
        return 'integer';
    }

    protected function typeInteger(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Map float to real.
     */
    protected function typeFloat(Fluent $column)
    {
        return 'real';
    }

    /**
     * Map double to real.
     */
    protected function typeDouble(Fluent $column)
    {
        return 'real';
    }

    /**
     * Map decimal to real.
     */
    protected function typeDecimal(Fluent $column)
    {
        return 'real';
    }

    /**
     * Map boolean to integer (0/1).
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'integer';
    }

    /**
     * Map date to text.
     */
    protected function typeDate(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map dateTime to text.
     */
    protected function typeDateTime(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map dateTimeTz to text.
     */
    protected function typeDateTimeTz(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map time to text.
     */
    protected function typeTime(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map timeTz to text.
     */
    protected function typeTimeTz(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map string (varchar) to text.
     */
    protected function typeString(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map text to text.
     */
    protected function typeText(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map enum to text (SQLite has no enum type).
     */
    protected function typeEnum(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map set to text.
     */
    protected function typeSet(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map JSON to text (SQLite stores JSON as text).
     */
    protected function typeJson(Fluent $column)
    {
        return 'text';
    }

    protected function typeJsonb(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map UUID to text.
     */
    protected function typeUuid(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map IP address to text.
     */
    protected function typeIpAddress(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map MAC address to text.
     */
    protected function typeMacAddress(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map longText to text.
     */
    protected function typeLongText(Fluent $column)
    {
        return 'text';
    }

    protected function typeMediumText(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map binary/blob types.
     */
    protected function typeBinary(Fluent $column)
    {
        return 'blob';
    }

    /**
     * Timestamp — use text. If useCurrent is set, apply default.
     */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->useCurrent) {
            $column->default = new \Illuminate\Database\Query\Expression("datetime('now')");
        }

        return 'text';
    }

    protected function typeTimestampTz(Fluent $column)
    {
        if ($column->useCurrent) {
            $column->default = new \Illuminate\Database\Query\Expression("datetime('now')");
        }

        return 'text';
    }

    protected function typeSoftDeletes(Fluent $column)
    {
        return 'text';
    }

    protected function typeSoftDeletesTz(Fluent $column)
    {
        return 'text';
    }

    /**
     * Map year to integer.
     */
    protected function typeYear(Fluent $column)
    {
        return 'integer';
    }

    // -------------------------------------------------------------------------
    //  Modifiers
    // -------------------------------------------------------------------------

    /**
     * Get the SQL for an auto-incrementing column modifier.
     *
     * In SQLite, autoincrement is: INTEGER PRIMARY KEY AUTOINCREMENT
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column)
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return ' primary key autoincrement';
        }

        return null;
    }
}
