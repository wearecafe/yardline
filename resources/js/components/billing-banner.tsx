import { Link, usePage } from '@inertiajs/react';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import { formatDate } from '@/lib/format';

/**
 * The trial countdown and payment problems, for owners and admins. Nothing
 * for everyone else: they can't act on it.
 */
export function BillingBanner() {
    const { billing, auth } = usePage().props;

    if (!billing || !auth.can.manageBusiness || !billing.has_access) {
        return null;
    }

    let message: string | null = null;
    let action = 'Billing';
    let urgent = false;

    if (billing.status === 'past_due') {
        message = 'Your last YardRunner payment didn’t go through.';
        action = 'Update payment details';
        urgent = true;
    } else if (billing.trial_days_left !== null) {
        const days = billing.trial_days_left;
        message =
            days === 1
                ? 'Your free trial ends today.'
                : `${days} days left in your free trial.`;
        action = 'Add payment details';
        urgent = days <= 3;
    } else if (billing.cancelling && billing.period_ends_at) {
        message = `Your YardRunner subscription ends on ${formatDate(billing.period_ends_at)}.`;
        action = 'Manage billing';
        urgent = true;
    }

    if (message === null) {
        return null;
    }

    return (
        <p
            className={
                urgent
                    ? 'border-b border-warning/20 bg-warning-soft px-4 py-1.5 text-center text-sm'
                    : 'border-b border-border bg-muted px-4 py-1.5 text-center text-sm'
            }
        >
            {message}{' '}
            <Link
                href={BillingController.edit()}
                className="font-medium underline underline-offset-4"
            >
                {action}
            </Link>
        </p>
    );
}
