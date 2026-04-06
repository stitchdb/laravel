<?php

namespace StitchDB\Laravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\SQLiteProcessor;

class StitchDBProcessor extends SQLiteProcessor
{
    /**
     * Process an "insert get ID" query — critical for Eloquent auto-incrementing models.
     * Laravel normally calls getPdo()->lastInsertId() which we handle via StitchDBPdoStub.
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);

        $id = $query->getConnection()->getLastInsertId();

        return is_numeric($id) ? (int) $id : $id;
    }
}
