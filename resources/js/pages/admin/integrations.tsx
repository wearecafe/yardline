import { Head, Link, router } from '@inertiajs/react';
import IntegrationHealthController from '@/actions/App/Http/Controllers/Admin/IntegrationHealthController';
import TenantController from '@/actions/App/Http/Controllers/Admin/TenantController';
import { BarChart } from '@/components/admin/parts';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/yard/chip';
import type { ChipTone } from '@/components/yard/chip';
import { EmptyRow, Table, Td, Th } from '@/components/yard/data-table';
import { PageBody, PageHeader, Section } from '@/components/yard/page-header';
import { formatAgo } from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    services: { name: string; value: string; detail: string; tone: ChipTone }[];
    issues: {
        key: string;
        tenant: { id: number; name: string } | null;
        integration: string;
        tone: 'danger' | 'warning';
        problem: string;
        detail: string | null;
        count: string | null;
        since: string | null;
        action: 'retry_quickbooks' | null;
    }[];
    hours: { hour: string; received: number; failed: number }[];
    failedJobs: {
        uuid: string;
        job: string;
        queue: string;
        error: string;
        failed_at: string;
    }[];
};

const dot: Record<string, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    info: 'bg-info',
    neutral: 'bg-muted-foreground',
};

function RetryQuickBooks({ tenantId }: { tenantId: number }) {
    return (
        <Button
            size="sm"
            onClick={() =>
                router.post(
                    IntegrationHealthController.retryQuickBooks.url(tenantId),
                    {},
                    { preserveScroll: true },
                )
            }
        >
            Send again
        </Button>
    );
}

export default function IntegrationHealth({
    services,
    issues,
    hours,
    failedJobs,
}: Props) {
    const chart = hours.map((h) => ({
        ...h,
        ok: h.received - h.failed,
    }));

    return (
        <>
            <Head title="Integration health" />
            <PageBody>
                <PageHeader
                    eyebrow="Last 24 hours"
                    title="Integration health"
                    actions={
                        <Button
                            variant="outline"
                            onClick={() => router.reload()}
                        >
                            Refresh
                        </Button>
                    }
                />

                <section
                    aria-label="Services"
                    className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3"
                >
                    {services.map((s) => (
                        <div
                            key={s.name}
                            className="flex flex-col gap-1 rounded-[10px] border border-border bg-card p-4"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm text-muted-foreground">
                                    {s.name}
                                </span>
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2.5 rounded-full',
                                        dot[s.tone],
                                    )}
                                />
                            </div>
                            <span className="figure text-[24px] leading-tight">
                                {s.value}
                            </span>
                            <span className="text-[13px] text-muted-foreground">
                                {s.detail}
                            </span>
                        </div>
                    ))}
                </section>

                <Section
                    flush
                    title="Open issues"
                    actions={
                        issues.length > 0 && (
                            <Chip tone="danger">{issues.length} open</Chip>
                        )
                    }
                >
                    <Table minWidth={900}>
                        <thead>
                            <tr>
                                <Th>Tenant</Th>
                                <Th>Integration</Th>
                                <Th>Problem</Th>
                                <Th>Affected</Th>
                                <Th>Since</Th>
                                <Th>
                                    <span className="sr-only">Action</span>
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {issues.length === 0 && (
                                <EmptyRow colSpan={6}>
                                    Everything is working.
                                </EmptyRow>
                            )}
                            {issues.map((i) => (
                                <tr key={i.key} className="align-top">
                                    <Td>
                                        {i.tenant ? (
                                            <Link
                                                href={TenantController.show(
                                                    i.tenant.id,
                                                )}
                                                className="font-semibold hover:underline"
                                            >
                                                {i.tenant.name}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                All tenants
                                            </span>
                                        )}
                                    </Td>
                                    <Td>
                                        <Chip tone={i.tone}>
                                            {i.integration}
                                        </Chip>
                                    </Td>
                                    <Td className="max-w-md">
                                        <div>{i.problem}</div>
                                        {i.detail && (
                                            <div className="figure mt-1 text-xs break-words text-muted-foreground">
                                                {i.detail}
                                            </div>
                                        )}
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        {i.count ?? '—'}
                                    </Td>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {i.since ? formatAgo(i.since) : '—'}
                                    </Td>
                                    <Td className="text-right">
                                        {i.action === 'retry_quickbooks' &&
                                            i.tenant && (
                                                <RetryQuickBooks
                                                    tenantId={i.tenant.id}
                                                />
                                            )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Section>

                <div className="flex flex-wrap items-start gap-5">
                    <Section
                        title="Webhooks per hour"
                        description="Stripe, all tenants"
                        className="min-w-0 flex-[1_1_480px]"
                    >
                        <BarChart
                            label="Webhooks received per hour over the last 24 hours, split into handled and failed or rejected"
                            data={chart}
                            height={150}
                            everyNthLabel={6}
                            xLabel={(h) =>
                                new Date(h.hour)
                                    .toLocaleTimeString('en-CA', {
                                        hour: 'numeric',
                                    })
                                    .replace('a.m.', 'am')
                                    .replace('p.m.', 'pm')
                            }
                            series={[
                                {
                                    key: 'ok',
                                    label: 'Handled',
                                    className: 'bg-[#a8a29e]',
                                },
                                {
                                    key: 'failed',
                                    label: 'Failed or rejected',
                                    className: 'bg-danger',
                                },
                            ]}
                        />
                    </Section>

                    <Section
                        flush
                        title="Failed background jobs"
                        description="Latest 20"
                        className="min-w-0 flex-[1_1_480px]"
                    >
                        <Table minWidth={520}>
                            <thead>
                                <tr>
                                    <Th>Job</Th>
                                    <Th>Error</Th>
                                    <Th>Failed</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {failedJobs.length === 0 && (
                                    <EmptyRow colSpan={4}>
                                        No failed jobs.
                                    </EmptyRow>
                                )}
                                {failedJobs.map((j) => (
                                    <tr key={j.uuid} className="align-top">
                                        <Td className="font-semibold whitespace-nowrap">
                                            {j.job}
                                            <div className="text-xs font-normal text-muted-foreground">
                                                {j.queue}
                                            </div>
                                        </Td>
                                        <Td className="figure max-w-xs text-xs break-words">
                                            {j.error}
                                        </Td>
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {formatAgo(j.failed_at)}
                                        </Td>
                                        <Td>
                                            <div className="flex justify-end gap-1.5">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            IntegrationHealthController.retryJob.url(
                                                                j.uuid,
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Retry
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.delete(
                                                            IntegrationHealthController.forgetJob.url(
                                                                j.uuid,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Clear
                                                </Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Section>
                </div>
            </PageBody>
        </>
    );
}
