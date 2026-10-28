<?php

namespace App\Imports;

use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One spreadsheet row: its cells by column key, and the errors found in
 * them. The readers turn a cell into a value or record why they couldn't.
 */
final class Row
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    /** @var list<array{column: string|null, message: string}> */
    public array $errors = [];

    /** What importing it will do, once it's been checked. */
    public string $action = self::CREATE;

    /** Why it will be skipped, if it will. */
    public ?string $note = null;

    /**
     * What the check read from the cells, for apply() to use.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * @param  int  $line  the line in the file, counting the headings as 1
     * @param  array<string, string>  $cells
     */
    public function __construct(
        public readonly int $line,
        public readonly array $cells,
    ) {}

    public function error(?string $column, string $message): void
    {
        $this->errors[] = ['column' => $column, 'message' => $message];
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function has(string $column): bool
    {
        return trim($this->cells[$column] ?? '') !== '';
    }

    public function text(string $column, int $max = 255, bool $required = false, ?string $label = null): ?string
    {
        $value = trim($this->cells[$column] ?? '');

        if ($value === '') {
            if ($required) {
                $this->error($column, ucfirst($label ?? str_replace('_', ' ', $column)).' is required.');
            }

            return null;
        }

        if (mb_strlen($value) > $max) {
            $this->error($column, "Keep it to {$max} characters.");

            return null;
        }

        return $value;
    }

    public function bool(string $column, ?bool $default = null): ?bool
    {
        $value = mb_strtolower(trim($this->cells[$column] ?? ''));

        return match (true) {
            $value === '' => $default,
            in_array($value, ['yes', 'y', 'true', '1', 'x', 'on'], true) => true,
            in_array($value, ['no', 'n', 'false', '0', 'off'], true) => false,
            default => $this->invalid($column, 'Use yes or no.'),
        };
    }

    /** Dollars, like 1,299.50 or $34.99, in cents. */
    public function money(string $column, bool $required = false, bool $allowNegative = false): ?int
    {
        $value = trim($this->cells[$column] ?? '');

        if ($value === '') {
            return $required ? $this->invalid($column, ucfirst(str_replace('_', ' ', $column)).' is required.') : null;
        }

        // Accounting exports write negatives as (120.00).
        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            $value = '-'.$m[1];
        }

        try {
            $cents = Money::parse($value);
        } catch (InvalidArgumentException) {
            return $this->invalid($column, 'Use an amount like 34.99.');
        }

        if ($cents !== null && $cents < 0 && ! $allowNegative) {
            return $this->invalid($column, 'Use an amount of 0 or more.');
        }

        return $cents;
    }

    /** A plain number, like 56 or 1.35. */
    public function number(string $column, bool $required = false, float $min = 0, bool $exclusive = false): ?string
    {
        $value = str_replace([',', ' '], '', trim($this->cells[$column] ?? ''));

        if ($value === '') {
            return $required ? $this->invalid($column, ucfirst(str_replace('_', ' ', $column)).' is required.') : null;
        }

        if (! is_numeric($value)) {
            return $this->invalid($column, 'Use a number, like 56 or 1.35.');
        }

        if ($exclusive ? (float) $value <= $min : (float) $value < $min) {
            return $this->invalid($column, $exclusive ? "Use a number above {$min}." : "Use {$min} or more.");
        }

        return $value;
    }

    /** A date written like 2026-09-15. */
    public function date(string $column, bool $required = false): ?CarbonImmutable
    {
        $value = trim($this->cells[$column] ?? '');

        if ($value === '') {
            return $required ? $this->invalid($column, ucfirst(str_replace('_', ' ', $column)).' is required.') : null;
        }

        $date = preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) ? CarbonImmutable::createFromFormat('!Y-n-j', $value) : null;

        if ($date === null || $date->format('Y-n-j') !== ltrim(preg_replace('/-0/', '-', $value) ?? '', '0')) {
            return $this->invalid($column, 'Use a date like 2026-09-15.');
        }

        return $date;
    }

    private function invalid(string $column, string $message): null
    {
        $this->error($column, $message);

        return null;
    }
}
