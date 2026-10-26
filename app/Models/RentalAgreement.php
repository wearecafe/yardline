<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The contract a customer signs: terms and a summary snapshot taken when it
 * was created, plus the signature and how it was given.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_id
 * @property string $terms
 * @property array<string, mixed> $summary
 * @property string|null $signer_name
 * @property string|null $signature_path
 * @property string|null $pdf_path
 * @property bool $acknowledged_training
 * @property int|null $id_document_id
 * @property string|null $signed_via
 * @property string|null $signed_ip
 * @property string|null $signed_user_agent
 * @property CarbonImmutable|null $signed_at
 * @property-read Rental $rental
 * @property-read CustomerDocument|null $idDocument
 */
#[Fillable([
    'rental_id', 'terms', 'summary', 'signer_name', 'signature_path', 'pdf_path',
    'acknowledged_training', 'id_document_id', 'signed_via', 'signed_ip',
    'signed_user_agent', 'signed_at',
])]
class RentalAgreement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'acknowledged_training' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * @return BelongsTo<CustomerDocument, $this>
     */
    public function idDocument(): BelongsTo
    {
        return $this->belongsTo(CustomerDocument::class, 'id_document_id');
    }
}
