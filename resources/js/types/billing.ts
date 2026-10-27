/**
 * The business's YardRunner subscription, shared with every page for the
 * trial and payment banner. Null for YardRunner staff and the demo yard.
 */
export type SharedBilling = {
    status:
        | 'trial'
        | 'active'
        | 'past_due'
        | 'expired'
        | 'suspended'
        | 'cancelled';
    has_access: boolean;
    subscribed: boolean;
    trial_days_left: number | null;
    trial_ends_at: string | null;
    cancelling: boolean;
    period_ends_at: string | null;
};
