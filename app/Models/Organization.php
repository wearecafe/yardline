<?php

namespace App\Models;

use App\Accounting\QuickBooks\SendsToQuickBooks;
use App\Audit\Audited;
use App\Enums\PaymentTerms;
use App\Tenancy\BelongsToTenant;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buying account: contractor, landscaper, builder.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $account_number
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country
 * @property int|null $price_list_id
 * @property int $credit_limit_cents
 * @property PaymentTerms $payment_terms
 * @property bool $credit_hold
 * @property bool $tax_exempt
 * @property string|null $tax_exempt_number
 * @property string|null $stripe_customer_id
 * @property string|null $notes
 * @property-read PriceList|null $priceList
 * @property-read Collection<int, OrganizationLocation> $locations
 * @property-read Collection<int, Customer> $contacts
 */
#[Fillable([
    'name', 'account_number', 'email', 'phone',
    'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country',
    'price_list_id', 'credit_limit_cents', 'payment_terms', 'credit_hold',
    'tax_exempt', 'tax_exempt_number', 'notes',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Audited, BelongsToTenant, HasFactory, SendsToQuickBooks;

    /** Changes to these are written to the audit log. */
    public const AUDITED = ['credit_limit_cents', 'payment_terms', 'credit_hold', 'price_list_id', 'tax_exempt'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_terms' => 'due_on_receipt',
        'credit_limit_cents' => 0,
        'credit_hold' => false,
        'tax_exempt' => false,
        'country' => 'CA',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit_cents' => 'integer',
            'payment_terms' => PaymentTerms::class,
            'credit_hold' => 'boolean',
            'tax_exempt' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Job sites and ship-to addresses.
     *
     * @return HasMany<OrganizationLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(OrganizationLocation::class)->orderByDesc('is_default')->orderBy('name');
    }

    /**
     * Same as locations(); named for route binding of {site}.
     *
     * @return HasMany<OrganizationLocation, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(OrganizationLocation::class);
    }

    /**
     * Everyone who buys for this organization, with their role.
     *
     * @return BelongsToMany<Customer, $this, CustomerMembership, 'membership'>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_organization')
            ->using(CustomerMembership::class)
            ->as('membership')
            ->withPivot(['id', 'tenant_id', 'role', 'can_charge_account'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<ContractPrice, $this>
     */
    public function contractPrices(): HasMany
    {
        return $this->hasMany(ContractPrice::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function hasAddress(): bool
    {
        return filled($this->address_line1) && filled($this->postal_code);
    }

    /**
     * @return array{line1: string|null, line2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string}
     */
    public function addressForTax(): array
    {
        return [
            'line1' => $this->address_line1,
            'line2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ];
    }
}
