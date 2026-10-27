import { Input } from '@/components/ui/input';
import { Field, NativeSelect } from '@/components/yard/field';
import { formatMoney } from '@/lib/format';

export type RefundApprovalProps = {
    /** The most this person can refund alone, or null for no limit. */
    limit_cents: number | null;
    /** People who can approve a refund above it. */
    approvers: { id: number; name: string }[];
};

export type ApprovalData = { approver_id: string; approver_secret: string };

/**
 * A refund above the person's limit: someone with Refund above limit
 * approves it on the spot with their password or time-clock PIN.
 */
export function RefundApprovalFields({
    id,
    approval,
    value,
    onChange,
    errors,
}: {
    id: string;
    approval: RefundApprovalProps;
    value: ApprovalData;
    onChange: (value: ApprovalData) => void;
    errors: Record<string, string | undefined>;
}) {
    return (
        <div className="grid gap-3 rounded-md bg-warning-soft/40 p-3">
            <p className="text-xs text-muted-foreground">
                {approval.limit_cents === null || approval.limit_cents === 0
                    ? 'Your role can’t refund on its own.'
                    : `You can refund up to ${formatMoney(approval.limit_cents)} on your own.`}{' '}
                Above that, someone who can refund above the limit approves it
                here.
            </p>
            <Field
                label="Approved by"
                htmlFor={`${id}-approver`}
                error={errors.approver_id}
            >
                <NativeSelect
                    id={`${id}-approver`}
                    value={value.approver_id}
                    onChange={(e) =>
                        onChange({ ...value, approver_id: e.target.value })
                    }
                >
                    <option value="">Choose someone</option>
                    {approval.approvers.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field
                label="Their password or PIN"
                htmlFor={`${id}-secret`}
                error={errors.approver_secret}
            >
                <Input
                    id={`${id}-secret`}
                    type="password"
                    autoComplete="off"
                    value={value.approver_secret}
                    onChange={(e) =>
                        onChange({ ...value, approver_secret: e.target.value })
                    }
                />
            </Field>
        </div>
    );
}

/** Whether a refund of this many cents needs someone to approve it. */
export function needsApproval(
    approval: RefundApprovalProps,
    cents: number,
): boolean {
    return approval.limit_cents !== null && cents > approval.limit_cents;
}
