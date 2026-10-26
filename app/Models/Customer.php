<?php

namespace App\Models;

use App\Commerce\SyncsWithCommerce;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * A person who buys: an organization's contact or a walk-in.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $organization_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_payment_method_id
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property string|null $notes
 * @property-read string $full_name
 * @property-read Organization|null $organization
 */
#[Fillable(['organization_id', 'first_name', 'last_name', 'email', 'phone', 'notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory, Notifiable, SyncsWithCommerce;

    /**
     * @var list<string>
     */
    protected $appends = ['full_name'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<CustomerDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    /**
     * @return HasMany<Rental, $this>
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    /**
     * "Visa 4417", if a card is saved.
     */
    public function cardLabel(): ?string
    {
        return $this->card_last4 ? trim(ucfirst((string) $this->card_brand).' '.$this->card_last4) : null;
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }
}
