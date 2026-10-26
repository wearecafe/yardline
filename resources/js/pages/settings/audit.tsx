import { Head, router } from '@inertiajs/react';
import AuditController from '@/actions/App/Http/Controllers/Settings/AuditController';
import Heading from '@/components/heading';
import { NativeSelect } from '@/components/yard/field';
import { History } from '@/components/yard/history';
import type { HistoryEvent } from '@/components/yard/history';
import { Pagination } from '@/components/yard/pagination';
import { SearchBox } from '@/components/yard/search-box';
import type { Option, Paginated } from '@/types';

export default function AuditLog({
    events,
    kinds,
    filters,
}: {
    events: Paginated<HistoryEvent>;
    kinds: Option[];
    filters: { kind: string | null; search: string | null };
}) {
    return (
        <>
            <Head title="Audit log" />
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Audit log"
                    description="Changes to orders, prices and credit terms, and who made them."
                />
                <div className="flex flex-wrap gap-3">
                    <SearchBox
                        value={filters.search ?? undefined}
                        placeholder="Order number, product or account"
                        extra={{ kind: filters.kind ?? undefined }}
                    />
                    <NativeSelect
                        aria-label="What changed"
                        className="w-auto"
                        value={filters.kind ?? ''}
                        onChange={(e) =>
                            router.get(
                                AuditController.index.url(),
                                {
                                    ...filters,
                                    kind: e.target.value || undefined,
                                },
                                { preserveState: true, replace: true },
                            )
                        }
                    >
                        <option value="">Everything</option>
                        {kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <History events={events.data} showSubject />
                <Pagination page={events} />
            </div>
        </>
    );
}
