import { Chip } from '@/components/yard/chip';

export type ImportSummary = {
    id: number;
    kind: string;
    kind_label: string;
    filename: string;
    status: 'checked' | 'imported' | 'discarded';
    total_rows: number;
    error_rows: number;
    created_count: number;
    updated_count: number;
    skipped_count: number;
    by: string | null;
    created_at: string;
    imported_at: string | null;
};

/** Where an uploaded spreadsheet stands. */
export function ImportStatus({ status }: { status: ImportSummary['status'] }) {
    return status === 'imported' ? (
        <Chip tone="success">Imported</Chip>
    ) : status === 'discarded' ? (
        <Chip>Discarded</Chip>
    ) : (
        <Chip tone="info">Checked, not imported</Chip>
    );
}
