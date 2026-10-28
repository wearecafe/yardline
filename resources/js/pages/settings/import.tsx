import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import ImportController from '@/actions/App/Http/Controllers/Settings/ImportController';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/yard/chip';
import { Table, Td, Th } from '@/components/yard/data-table';
import { Checkbox } from '@/components/yard/field';
import { Notice } from '@/components/yard/page-header';
import { formatDateTime } from '@/lib/format';
import { ImportStatus } from '@/components/yard/import-status';
import type { ImportSummary } from '@/components/yard/import-status';

type Props = {
    import: ImportSummary & {
        columns: string[];
        errors: { row: number; column: string | null; message: string }[];
        error_count: number;
        preview: {
            row: number;
            action: 'create' | 'update' | 'skip';
            note: string | null;
            ok: boolean;
            cells: Record<string, string>;
        }[];
        file_kept: boolean;
    };
    fromSetup: boolean;
};

const ORDER = [
    'products',
    'price_lists',
    'organizations',
    'job_sites',
    'stock',
    'balances',
];

/** How many columns of each row the preview shows. */
const PREVIEW_COLUMNS = 6;

/**
 * One uploaded spreadsheet: what importing it will do, row by row, and
 * what's wrong with it; then what it did.
 */
export default function ImportPreview({ import: imp, fromSetup }: Props) {
    const { errors: pageErrors } = usePage().props as {
        errors?: Record<string, string>;
    };
    const form = useForm({ leave_out_errors: false, setup: fromSetup });
    const fileProblems = imp.errors.filter((e) => e.row === 0);
    const valid = imp.total_rows - imp.error_rows;
    const checked = imp.status === 'checked';
    const imported = imp.status === 'imported';
    const columns = imp.columns
        .filter((c) => imp.preview.some((p) => (p.cells[c] ?? '') !== ''))
        .slice(0, PREVIEW_COLUMNS);
    const next = ORDER[ORDER.indexOf(imp.kind) + 1];
    const canImport =
        checked &&
        fileProblems.length === 0 &&
        valid > 0 &&
        (imp.error_rows === 0 || form.data.leave_out_errors);

    return (
        <>
            <Head title={imp.filename} />
            <Heading
                title={imp.filename}
                description={`${imp.kind_label} · uploaded ${formatDateTime(imp.created_at)}${imp.by ? ` by ${imp.by}` : ''}`}
            />

            <div className="grid gap-6">
                <div className="flex flex-wrap items-center gap-2">
                    <ImportStatus status={imp.status} />
                </div>

                {pageErrors?.import && (
                    <Notice tone="danger">{pageErrors.import}</Notice>
                )}

                {fileProblems.map((p) => (
                    <Notice key={p.message} tone="danger">
                        {p.message}
                    </Notice>
                ))}

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat label="Rows" value={imp.total_rows} />
                    <Stat
                        label={imported ? 'Added' : 'New'}
                        value={imp.created_count}
                    />
                    <Stat
                        label={imported ? 'Updated' : 'Will update'}
                        value={imp.updated_count}
                    />
                    <Stat
                        label={imp.error_rows > 0 ? 'With errors' : 'Skipped'}
                        value={
                            imp.error_rows > 0
                                ? imp.error_rows
                                : imp.skipped_count
                        }
                        danger={imp.error_rows > 0}
                    />
                </dl>

                {imported && (
                    <Notice tone="success">
                        Imported {formatDateTime(imp.imported_at)}:{' '}
                        {imp.created_count} added, {imp.updated_count} updated
                        {imp.skipped_count > 0
                            ? `, ${imp.skipped_count} already there`
                            : ''}
                        {imp.error_rows > 0
                            ? `. ${imp.error_rows} rows with errors were left out.`
                            : '.'}
                    </Notice>
                )}

                {imp.error_count > 0 && (
                    <section className="grid gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Heading
                                variant="small"
                                title="What to fix"
                                description="Fix these in your spreadsheet and upload it again. The error report has just these rows, with what's wrong beside each."
                            />
                            {imp.file_kept && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={ImportController.errors.url(
                                            imp.id,
                                        )}
                                    >
                                        <Download className="size-4" />
                                        Error report
                                    </a>
                                </Button>
                            )}
                        </div>
                        <Table minWidth={520}>
                            <thead>
                                <tr>
                                    <Th>Line</Th>
                                    <Th>Column</Th>
                                    <Th>Problem</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {imp.errors
                                    .filter((e) => e.row > 0)
                                    .map((e, i) => (
                                        <tr key={`${e.row}-${i}`}>
                                            <Td className="figure">{e.row}</Td>
                                            <Td className="font-mono text-xs">
                                                {e.column ?? '—'}
                                            </Td>
                                            <Td>{e.message}</Td>
                                        </tr>
                                    ))}
                            </tbody>
                        </Table>
                        {imp.error_count > imp.errors.length && (
                            <p className="text-sm text-muted-foreground">
                                Showing the first {imp.errors.length} of{' '}
                                {imp.error_count}. The error report has them
                                all.
                            </p>
                        )}
                    </section>
                )}

                {checked && imp.preview.length > 0 && (
                    <section className="grid gap-3">
                        <Heading
                            variant="small"
                            title="Preview"
                            description={
                                imp.total_rows > imp.preview.length
                                    ? `The first ${imp.preview.length} of ${imp.total_rows} rows.`
                                    : 'Every row, and what importing it will do.'
                            }
                        />
                        <Table minWidth={640}>
                            <thead>
                                <tr>
                                    <Th>Line</Th>
                                    <Th />
                                    {columns.map((c) => (
                                        <Th key={c} className="font-mono">
                                            {c}
                                        </Th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {imp.preview.map((p) => (
                                    <tr key={p.row}>
                                        <Td className="figure">{p.row}</Td>
                                        <Td>
                                            <RowAction row={p} />
                                        </Td>
                                        {columns.map((c) => (
                                            <Td
                                                key={c}
                                                className="max-w-48 truncate"
                                            >
                                                {p.cells[c] ?? ''}
                                            </Td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </section>
                )}

                {checked && (
                    <form
                        className="grid gap-4 border-t border-border pt-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(ImportController.run.url(imp.id), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        {imp.error_rows > 0 && valid > 0 && (
                            <Checkbox
                                id="leave_out_errors"
                                label={`Import the other ${valid} rows and leave out the ${imp.error_rows} with errors`}
                                hint="Otherwise, fix the file and upload it again."
                                checked={form.data.leave_out_errors}
                                onChange={(on) =>
                                    form.setData('leave_out_errors', on)
                                }
                            />
                        )}
                        <div className="flex flex-wrap gap-3">
                            <Button
                                type="submit"
                                disabled={!canImport || form.processing}
                            >
                                {form.processing
                                    ? 'Importing…'
                                    : `Import ${imp.error_rows > 0 ? valid : imp.total_rows} rows`}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        ImportController.destroy.url(imp.id, {
                                            query: fromSetup
                                                ? { setup: 1 }
                                                : {},
                                        }),
                                    )
                                }
                            >
                                Discard
                            </Button>
                        </div>
                    </form>
                )}

                {imported && (
                    <div className="flex flex-wrap gap-3 border-t border-border pt-6">
                        {next && (
                            <Button asChild>
                                <Link
                                    href={ImportController.index.url({
                                        query: {
                                            kind: next,
                                            ...(fromSetup ? { setup: 1 } : {}),
                                        },
                                    })}
                                >
                                    Next: import more
                                </Link>
                            </Button>
                        )}
                        {fromSetup && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={SetupController.show.url({
                                        step: 'products',
                                    })}
                                >
                                    Back to setup
                                </Link>
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

function Stat({
    label,
    value,
    danger = false,
}: {
    label: string;
    value: number;
    danger?: boolean;
}) {
    return (
        <div className="rounded-[10px] border border-border bg-card px-4 py-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={`figure mt-0.5 text-xl font-semibold ${danger ? 'text-danger' : ''}`}
            >
                {value.toLocaleString()}
            </dd>
        </div>
    );
}

function RowAction({ row }: { row: Props['import']['preview'][number] }) {
    if (!row.ok) {
        return <Chip tone="danger">Error</Chip>;
    }

    if (row.action === 'skip') {
        return (
            <span title={row.note ?? undefined}>
                <Chip>Skip</Chip>
            </span>
        );
    }

    return row.action === 'update' ? (
        <Chip tone="info">Update</Chip>
    ) : (
        <Chip tone="success">New</Chip>
    );
}

ImportPreview.layout = {
    breadcrumbs: [{ title: 'Import data', href: ImportController.index() }],
    wide: true,
};
