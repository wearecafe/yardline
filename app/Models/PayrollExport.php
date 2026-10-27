<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that a pay period's hours went to payroll, and how.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $pay_period_id
 * @property string $format summary | adp | quickbooks
 * @property int $employees
 * @property int $regular_minutes
 * @property int $overtime_minutes
 * @property int|null $user_id
 * @property string|null $note
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 */
#[Fillable(['pay_period_id', 'format', 'employees', 'regular_minutes', 'overtime_minutes', 'user_id', 'note', 'details'])]
class PayrollExport extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'employees' => 'integer',
            'regular_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
