<?php

namespace Database\Factories;

use App\Enums\PaymentTerms;
use App\Models\Organization;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => CurrentTenant::id() ?? Tenant::factory(),
            'name' => fake()->company().' Landscaping',
            'account_number' => fake()->unique()->numerify('C-#####'),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'country' => 'CA',
            'credit_limit_cents' => 0,
            'payment_terms' => PaymentTerms::DueOnReceipt,
        ];
    }

    public function withCredit(int $limitCents, PaymentTerms $terms = PaymentTerms::Net30): static
    {
        return $this->state(fn () => ['credit_limit_cents' => $limitCents, 'payment_terms' => $terms]);
    }
}
