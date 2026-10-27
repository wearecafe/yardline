<?php

namespace App\Payments\Connect;

use App\Models\Tenant;

/**
 * Where a yard is with card payments, in words for the Payments page and
 * the back office.
 */
final class AccountStatus
{
    public const NOT_STARTED = 'not_started';

    public const ONBOARDING = 'onboarding';

    public const ACTION_REQUIRED = 'action_required';

    public const PAUSED = 'paused';

    public const ACTIVE = 'active';

    /**
     * @param  list<string>  $due  requirement keys Stripe is asking for
     */
    private function __construct(
        public readonly string $state,
        public readonly string $label,
        public readonly string $tone,
        public readonly string $summary,
        public readonly array $due,
    ) {}

    public static function for(Tenant $tenant): self
    {
        $requirements = $tenant->stripe_requirements ?? [];
        $due = array_values(array_unique([...($requirements['past_due'] ?? []), ...($requirements['currently_due'] ?? [])]));

        if ($tenant->stripe_account_id === null) {
            return new self(self::NOT_STARTED, 'Not set up', 'neutral', 'Set up card payments to take cards at the counter, on payment links and for rentals.', []);
        }

        if (! $tenant->stripe_details_submitted) {
            return new self(self::ONBOARDING, 'Setup not finished', 'warning', 'Finish entering your business and bank details to start taking cards.', $due);
        }

        if (! $tenant->stripe_charges_enabled) {
            return new self(self::PAUSED, 'Card payments paused', 'danger', 'Card payments are paused until the details below are sent.', $due);
        }

        if ($due !== []) {
            return new self(self::ACTION_REQUIRED, 'Details needed', 'warning', 'Send the details below to keep card payments and payouts running.', $due);
        }

        return new self(self::ACTIVE, 'Active', 'success', $tenant->stripe_payouts_enabled ? 'Taking card payments and paying out to your bank.' : 'Taking card payments. Payouts start once your bank account is verified.', []);
    }

    /**
     * @return array{state: string, label: string, tone: string, summary: string, due: list<string>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'label' => $this->label,
            'tone' => $this->tone,
            'summary' => $this->summary,
            'due' => $this->due,
        ];
    }
}
