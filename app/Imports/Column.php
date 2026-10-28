<?php

namespace App\Imports;

/**
 * One column of an import spreadsheet, as the template and the import
 * page describe it.
 */
final readonly class Column
{
    /**
     * @param  list<string>  $aliases  other headings accepted for it
     */
    public function __construct(
        public string $key,
        public string $help,
        public bool $required = false,
        public array $aliases = [],
    ) {}
}
