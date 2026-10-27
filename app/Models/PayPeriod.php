<?php

namespace App\Models;

use App\Audit\Audited;
use App\Enums\PayPeriodStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on inclusive
 * @property PayPeriodStatus $status
 * @property CarbonImmutable|null $locked_at
 * @property int|null $locked_by
 * @property CarbonImmutable|null $exported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['starts_on', 'ends_on', 'status'])]
class PayPeriod extends Model
{
    use Audited, BelongsToTenant;

    public const AUDITED = ['status'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => PayPeriodStatus::class,
            'locked_at' => 'immutable_datetime',
            'exported_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === PayPeriodStatus::Open;
    }

    public function contains(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->starts_on->toDateString() && $day <= $this->ends_on->toDateString();
    }

    /** "Oct 18 – Oct 31". */
    public function label(): string
    {
        $sameYear = $this->starts_on->year === $this->ends_on->year;

        return $this->starts_on->format($sameYear ? 'M j' : 'M j, Y').' – '.$this->ends_on->format('M j, Y');
    }

    /**
     * Shown in the audit log.
     */
    public function getNameAttribute(): string
    {
        return 'Pay period '.$this->label();
    }

    /**
     * @return HasMany<Timesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    /**
     * @return HasMany<PayrollExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(PayrollExport::class);
    }
}
