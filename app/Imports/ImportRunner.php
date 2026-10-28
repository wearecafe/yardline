<?php

namespace App\Imports;

use App\Audit\Audit;
use App\Models\Import;
use App\Models\User;
use App\Orders\OrderRules;
use App\Support\Files;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Checks an uploaded spreadsheet (the preview) and imports it.
 *
 * Importing runs the check again, row by row, as each row is loaded, so
 * a row can rely on what the rows before it created. It's all or nothing:
 * if any row fails, nothing is kept, unless the person chose to leave out
 * the rows with errors.
 */
class ImportRunner
{
    /** Errors kept for the preview and the error report. */
    public const MAX_ERRORS = 2000;

    /** Rows shown in the preview. */
    public const PREVIEW_ROWS = 50;

    public function upload(ImportKind $kind, UploadedFile $file, ?User $user): Import
    {
        $import = Import::query()->create([
            'kind' => $kind,
            'filename' => mb_substr($file->getClientOriginalName() ?: 'import.csv', 0, 255),
            'path' => Files::storeUpload($file, 'imports'),
            'created_by' => $user?->id,
        ]);

        return $this->check($import, $user);
    }

    /** Check every row without saving anything, and keep the results. */
    public function check(Import $import, ?User $user): Import
    {
        $file = $this->file($import);
        $context = new ImportContext($import, $user, preview: true);
        $importer = $import->kind->importer();
        $preview = [];
        $counts = [Row::CREATE => 0, Row::UPDATE => 0, Row::SKIP => 0];

        foreach ($file->rows as $row) {
            $importer->check($row, $context);

            if ($row->ok()) {
                $counts[$row->action]++;
            }

            if (count($preview) < self::PREVIEW_ROWS) {
                $preview[] = self::previewRow($row);
            }
        }

        $import->forceFill([
            'status' => Import::CHECKED,
            'total_rows' => count($file->rows),
            'error_rows' => count(array_filter($file->rows, fn (Row $r) => ! $r->ok())),
            'created_count' => $counts[Row::CREATE],
            'updated_count' => $counts[Row::UPDATE],
            'skipped_count' => $counts[Row::SKIP],
            'errors' => self::errors($file),
            'preview' => $preview,
        ])->save();

        return $import;
    }

    /**
     * Load the rows. With $leaveOutErrors, rows that fail are left out and
     * the rest are kept; otherwise one failure keeps nothing.
     */
    public function import(Import $import, ?User $user, bool $leaveOutErrors = false): Import
    {
        if ($import->status !== Import::CHECKED) {
            OrderRules::fail('This file has already been '.($import->status === Import::IMPORTED ? 'imported' : 'discarded').'. Upload it again to import it.', 'import');
        }

        $file = $this->file($import);

        if ($file->problems !== []) {
            OrderRules::fail($file->problems[0], 'import');
        }

        $importer = $import->kind->importer();
        $counts = [Row::CREATE => 0, Row::UPDATE => 0, Row::SKIP => 0];

        try {
            DB::transaction(function () use ($file, $import, $user, $importer, $leaveOutErrors, &$counts) {
                $context = new ImportContext($import, $user, preview: false);

                foreach ($file->rows as $row) {
                    $importer->check($row, $context);

                    if (! $row->ok()) {
                        continue;
                    }

                    $counts[$importer->apply($row, $context)]++;
                }

                $failed = array_filter($file->rows, fn (Row $r) => ! $r->ok());

                if ($failed !== [] && ! $leaveOutErrors) {
                    throw new ImportFailed;
                }
            });
        } catch (ImportFailed) {
            // Nothing was kept: show what went wrong this time.
            $import->forceFill([
                'error_rows' => count(array_filter($file->rows, fn (Row $r) => ! $r->ok())),
                'errors' => self::errors($file),
            ])->save();

            OrderRules::fail('Some rows have errors, so nothing was imported. Fix them and upload the file again, or import without them.', 'import');
        }

        $import->forceFill([
            'status' => Import::IMPORTED,
            'imported_at' => now(),
            'error_rows' => count(array_filter($file->rows, fn (Row $r) => ! $r->ok())),
            'created_count' => $counts[Row::CREATE],
            'updated_count' => $counts[Row::UPDATE],
            'skipped_count' => $counts[Row::SKIP],
            'errors' => self::errors($file),
        ])->save();

        Audit::record($import, 'imported', note: "{$import->kind->label()} from {$import->filename}: {$counts[Row::CREATE]} new, {$counts[Row::UPDATE]} updated, {$counts[Row::SKIP]} skipped");

        return $import;
    }

    /**
     * The rows with errors, as CSV: their cells as uploaded, plus the line
     * and what's wrong, so they can be fixed and uploaded again.
     */
    public function errorReport(Import $import): string
    {
        $file = $this->file($import);
        $byLine = [];

        foreach ($import->errors ?? [] as $error) {
            $byLine[$error['row']][] = ($error['column'] !== null ? "{$error['column']}: " : '').$error['message'];
        }

        $keys = array_map(fn (Column $c) => $c->key, $import->kind->columns());
        $out = CsvFile::memory();
        fputcsv($out, ['line', 'errors', ...$keys], escape: '');

        foreach ($file->problems as $problem) {
            fputcsv($out, ['', $problem], escape: '');
        }

        foreach ($file->rows as $row) {
            if (isset($byLine[$row->line])) {
                fputcsv($out, [$row->line, implode(' ', $byLine[$row->line]), ...array_map(fn ($k) => $row->cells[$k] ?? '', $keys)], escape: '');
            }
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function file(Import $import): CsvFile
    {
        $contents = Files::disk()->get($import->path);

        if ($contents === null) {
            throw new RuntimeException("The uploaded file for import {$import->id} is missing.");
        }

        return new CsvFile($contents, $import->kind);
    }

    /**
     * @return list<array{row: int, column: string|null, message: string}>
     */
    private static function errors(CsvFile $file): array
    {
        $errors = array_map(fn (string $p) => ['row' => 0, 'column' => null, 'message' => $p], $file->problems);

        foreach ($file->rows as $row) {
            foreach ($row->errors as $error) {
                $errors[] = ['row' => $row->line, ...$error];
            }
        }

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /**
     * @return array{row: int, action: string, note: string|null, ok: bool, cells: array<string, string>}
     */
    private static function previewRow(Row $row): array
    {
        return [
            'row' => $row->line,
            'action' => $row->action,
            'note' => $row->note,
            'ok' => $row->ok(),
            'cells' => $row->cells,
        ];
    }
}
