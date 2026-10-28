<?php

namespace App\Imports;

use RuntimeException;

/**
 * Reads an uploaded spreadsheet saved as CSV: comma, semicolon or tab
 * separated, with or without a byte-order mark, in UTF-8 or the Windows
 * encoding Excel sometimes saves. Headings are matched to the kind's
 * columns, ignoring case, spaces and punctuation, and their aliases.
 */
final class CsvFile
{
    public const MAX_ROWS = 5000;

    /** @var list<Row> */
    public array $rows = [];

    /** @var list<string> problems with the file as a whole */
    public array $problems = [];

    /** @var list<string> headings that aren't one of the kind's columns */
    public array $ignored = [];

    /** @var array<int, string> column number => column key */
    private array $map = [];

    public function __construct(string $contents, private readonly ImportKind $kind)
    {
        $this->read($contents);
    }

    private function read(string $contents): void
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        if (trim($contents) === '') {
            $this->problems[] = 'The file is empty.';

            return;
        }

        $stream = self::memory();
        fwrite($stream, $contents);
        rewind($stream);

        $firstLine = strtok($contents, "\n") ?: '';
        $delimiter = $this->delimiter($firstLine);
        $headings = fgetcsv($stream, separator: $delimiter, escape: '');

        if ($headings === false) {
            $this->problems[] = 'The file has no headings.';

            return;
        }

        $this->mapHeadings(array_map(fn ($h) => (string) $h, $headings));

        if ($this->problems !== []) {
            return;
        }

        $line = 1;

        while (($cells = fgetcsv($stream, separator: $delimiter, escape: '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                continue; // a blank line
            }

            if (count($this->rows) >= self::MAX_ROWS) {
                $this->problems[] = 'Keep each file to '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.';
                $this->rows = [];

                return;
            }

            $row = [];

            foreach ($this->map as $index => $key) {
                $row[$key] = trim((string) ($cells[$index] ?? ''));
            }

            $this->rows[] = new Row($line, $row);
        }

        if ($this->rows === []) {
            $this->problems[] = 'The file has headings but no rows.';
        }
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  list<string>  $headings
     */
    private function mapHeadings(array $headings): void
    {
        $lookup = [];

        foreach ($this->kind->columns() as $column) {
            foreach ([$column->key, ...$column->aliases] as $name) {
                $lookup[self::normalize($name)] = $column->key;
            }
        }

        foreach ($headings as $index => $heading) {
            $key = $lookup[self::normalize($heading)] ?? null;

            if ($key === null) {
                if (trim($heading) !== '') {
                    $this->ignored[] = trim($heading);
                }

                continue;
            }

            if (! in_array($key, $this->map, true)) {
                $this->map[$index] = $key;
            }
        }

        foreach ($this->kind->columns() as $column) {
            if ($column->required && ! in_array($column->key, $this->map, true)) {
                $this->problems[] = "The file needs a {$column->key} column.";
            }
        }
    }

    /**
     * A scratch stream for building or reading CSV in memory.
     *
     * @return resource
     */
    public static function memory()
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Couldn’t open a temporary stream.');
        }

        return $stream;
    }

    public static function normalize(string $heading): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($heading))), '_');
    }
}
