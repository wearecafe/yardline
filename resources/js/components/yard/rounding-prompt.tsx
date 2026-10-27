import { Button } from '@/components/ui/button';
import { getJson } from '@/lib/json';

export type FitResult = {
    fits: boolean;
    problem: string | null;
    proposal: {
        direction: 'up' | 'down';
        summary: string;
        requested: string;
        total: string;
        exact: boolean;
        lines: { product_unit_id: number; unit: string; quantity: string }[];
    } | null;
};

/**
 * Asks the server whether a quantity can be sold as entered, and what the
 * product's rounding offers when it can't.
 */
export function checkFit(
    url: string,
    quantity: string,
    productUnitId: number | string | null,
    signal?: AbortSignal,
): Promise<FitResult> {
    return getJson<FitResult>(
        url,
        { quantity, product_unit_id: productUnitId || null },
        signal,
    );
}

/**
 * The confirm step before a rounded quantity goes on an order: what was
 * asked for, the mix of units for sale it becomes, and the base total.
 */
export function RoundingPrompt({
    fit,
    onAccept,
    onCancel,
    busy = false,
}: {
    fit: FitResult;
    onAccept: () => void;
    onCancel: () => void;
    busy?: boolean;
}) {
    const proposal = fit.proposal;

    if (!proposal) {
        return null;
    }

    const heading = proposal.exact
        ? `${proposal.requested} goes on as ${proposal.summary}`
        : `Round ${proposal.direction} ${proposal.requested} to ${proposal.total}?`;

    return (
        <div
            role="alertdialog"
            aria-label="Confirm rounding"
            className="grid gap-2 rounded-md border border-warning/30 bg-warning-soft p-3 text-sm"
        >
            <div className="font-medium text-foreground">{heading}</div>
            <p className="text-muted-foreground">
                {fit.problem} {proposal.exact ? 'That is' : 'Sold as'}{' '}
                <span className="font-medium text-foreground">
                    {proposal.summary}
                </span>
                {proposal.exact ? '.' : ` (${proposal.total}).`}
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    onClick={onAccept}
                    disabled={busy}
                >
                    Use {proposal.summary}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={onCancel}
                    disabled={busy}
                >
                    Change quantity
                </Button>
            </div>
        </div>
    );
}
