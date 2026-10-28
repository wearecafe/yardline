<?php

namespace App\Imports;

/**
 * Checks and loads one kind of spreadsheet, a row at a time.
 */
interface Importer
{
    /**
     * Check a row: record its errors on it, and what importing it will do
     * (Row::CREATE, UPDATE or SKIP). In a preview nothing is saved, so the
     * context remembers what earlier rows will create.
     */
    public function check(Row $row, ImportContext $context): void;

    /**
     * Load a row that passed its check. Returns what it did: Row::CREATE,
     * UPDATE or SKIP.
     */
    public function apply(Row $row, ImportContext $context): string;
}
