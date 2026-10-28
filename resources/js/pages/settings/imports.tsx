import { Head, Link, useForm } from '@inertiajs/react';
import { Check, Download, FileUp } from 'lucide-react';
import { useState } from 'react';
import ImportController from '@/actions/App/Http/Controllers/Settings/ImportController';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { ImportStatus } from '@/components/yard/import-status';
import type { ImportSummary } from '@/components/yard/import-status';
import { Notice } from '@/components/yard/page-header';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type Kind = {
    value: string;
    label: string;
    description: string;
    needs: string | null;
    have: number;
    columns: { key: string; help: string; required: boolean }[];
};

type Props = {
    kinds: Kind[];
    selected: string | null;
    fromSetup: boolean;
    history: ImportSummary[];
    maxRows: number;
};

/**
 * Settings → Import data: load a yard's existing data from spreadsheets,
 * in order, each checked before anything changes.
 */
export default function Imports({
    kinds,
    selected,
    fromSetup,
    history,
    maxRows,
}: Props) {
    const [open, setOpen] = useState<string>(selected ?? kinds[0].value);

    return (
        <>
            <Head title="Import data" />
            <Heading
                title="Import data"
                description="Bring over what you already have, from your old system or a spreadsheet. Each file is checked first, and nothing changes until you import it."
            />

            {fromSetup && (
                <Notice className="mb-6">
                    Setting up YardRunner?{' '}
                    <Link
                        href={SetupController.show.url({ step: 'products' })}
                        className="font-medium underline underline-offset-4"
                    >
                        Back to setup
                    </Link>{' '}
                    when you’re done.
                </Notice>
            )}

            <p className="mb-4 text-sm text-muted-foreground">
                Save each sheet as CSV, up to {maxRows.toLocaleString()} rows
                per file. Headings can be in any order and any case; the
                templates show what each column takes. Each kind has a blank
                template to fill in and a sample with example rows; the six
                samples go together, so you can load them in order to try it
                (change Main yard to one of your yards first). Loading a file
                again updates what it loaded before instead of adding it twice.
            </p>

            <ol className="grid gap-3">
                {kinds.map((kind, i) => (
                    <KindCard
                        key={kind.value}
                        kind={kind}
                        step={i + 1}
                        open={open === kind.value}
                        onOpen={() => setOpen(kind.value)}
                        fromSetup={fromSetup}
                    />
                ))}
            </ol>

            <section className="mt-10">
                <Heading
                    variant="small"
                    title="Uploads"
                    description="Files checked or imported here, newest first."
                />
                <div className="mt-3">
                    <Table minWidth={620}>
                        <thead>
                            <tr>
                                <Th>File</Th>
                                <Th>What</Th>
                                <Th>Status</Th>
                                <Th numeric>Rows</Th>
                                <Th>When</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.length === 0 && (
                                <EmptyRow colSpan={5}>
                                    Nothing uploaded yet.
                                </EmptyRow>
                            )}
                            {history.map((h) => (
                                <tr key={h.id}>
                                    <Td>
                                        <Link
                                            href={ImportController.show.url(
                                                h.id,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {h.filename}
                                        </Link>
                                    </Td>
                                    <Td>{h.kind_label}</Td>
                                    <Td>
                                        <ImportStatus status={h.status} />
                                    </Td>
                                    <Td numeric>{h.total_rows}</Td>
                                    <Td className="text-muted-foreground">
                                        {formatDateTime(
                                            h.imported_at ?? h.created_at,
                                        )}
                                        {h.by ? ` · ${h.by}` : ''}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            </section>
        </>
    );
}

function KindCard({
    kind,
    step,
    open,
    onOpen,
    fromSetup,
}: {
    kind: Kind;
    step: number;
    open: boolean;
    onOpen: () => void;
    fromSetup: boolean;
}) {
    const form = useForm<{ kind: string; file: File | null; setup: boolean }>({
        kind: kind.value,
        file: null,
        setup: fromSetup,
    });
    const [showColumns, setShowColumns] = useState(false);

    return (
        <li
            className={cn(
                'rounded-[10px] border border-border bg-card',
                open ? 'p-5' : 'px-5 py-3.5',
            )}
        >
            <button
                type="button"
                onClick={onOpen}
                className="flex w-full items-start gap-3 text-left"
                aria-expanded={open}
            >
                <span
                    className={cn(
                        'figure mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                        kind.have > 0
                            ? 'bg-success-soft text-success'
                            : 'bg-secondary text-subtle',
                    )}
                >
                    {kind.have > 0 ? <Check className="size-3.5" /> : step}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block font-semibold">{kind.label}</span>
                    <span className="block text-sm text-muted-foreground">
                        {kind.have > 0
                            ? `${kind.have.toLocaleString()} in YardRunner now.`
                            : kind.description}
                    </span>
                </span>
            </button>

            {open && (
                <div className="mt-4 grid gap-4 pl-9">
                    {kind.have > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {kind.description}
                        </p>
                    )}
                    {kind.needs && (
                        <p className="text-sm text-muted-foreground">
                            {kind.needs}
                        </p>
                    )}

                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(ImportController.store.url(), {
                                forceFormData: true,
                            });
                        }}
                    >
                        <label
                            htmlFor={`file-${kind.value}`}
                            className="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-input px-4 py-3 text-sm hover:bg-accent"
                        >
                            <FileUp className="size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 truncate">
                                {form.data.file
                                    ? form.data.file.name
                                    : 'Choose a CSV file'}
                            </span>
                            <input
                                id={`file-${kind.value}`}
                                type="file"
                                accept=".csv,text/csv"
                                className="sr-only"
                                onChange={(e) =>
                                    form.setData(
                                        'file',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                        <InputError message={form.errors.file} />
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                disabled={!form.data.file || form.processing}
                            >
                                {form.processing ? 'Checking…' : 'Check file'}
                            </Button>
                            <Button variant="ghost" asChild>
                                <a
                                    href={ImportController.template.url(
                                        kind.value,
                                    )}
                                    title="Just the headings, ready to fill in"
                                >
                                    <Download className="size-4" />
                                    Template
                                </a>
                            </Button>
                            <Button variant="ghost" asChild>
                                <a
                                    href={ImportController.sample.url(
                                        kind.value,
                                    )}
                                    title="Filled in with example rows"
                                >
                                    <Download className="size-4" />
                                    Sample
                                </a>
                            </Button>
                            <button
                                type="button"
                                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                onClick={() => setShowColumns((s) => !s)}
                            >
                                {showColumns ? 'Hide columns' : 'Columns'}
                            </button>
                        </div>
                    </form>

                    {showColumns && (
                        <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[max-content_1fr]">
                            {kind.columns.map((c) => (
                                <div key={c.key} className="contents">
                                    <dt className="font-mono text-xs leading-5">
                                        {c.key}
                                        {c.required && (
                                            <span className="text-danger">
                                                {' '}
                                                *
                                            </span>
                                        )}
                                    </dt>
                                    <dd className="text-muted-foreground">
                                        {c.help || '—'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
            )}
        </li>
    );
}

Imports.layout = {
    breadcrumbs: [{ title: 'Import data', href: ImportController.index() }],
};
