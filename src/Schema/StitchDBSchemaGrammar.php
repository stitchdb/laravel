<?php

namespace StitchDB\Laravel\Schema;

use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;
use Illuminate\Database\Schema\Blueprint;

class StitchDBSchemaGrammar extends SQLiteGrammar
{
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
     * Timestamps.
     */
    protected function typeTimestamp(Fluent $column)
    {
        return 'datetime';
    }

    protected function typeTimestampTz(Fluent $column)
    {
        return 'datetime';
    }

    protected function typeSoftDeletes(Fluent $column)
    {
        return 'datetime';
    }

    protected function typeSoftDeletesTz(Fluent $column)
    {
        return 'datetime';
    }

    /**
     * Map year to integer.
     */
    protected function typeYear(Fluent $column)
    {
        return 'integer';
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
}
