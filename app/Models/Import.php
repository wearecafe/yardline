<?php

namespace App\Models;

use App\Imports\ImportKind;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spreadsheet uploaded to load a yard's existing data. It's checked
 * when it's uploaded (status checked), then imported or discarded.
 *
 * @property int $id
 * @property int $tenant_id
 * @property ImportKind $kind
 * @property string $filename
 * @property string $path where the uploaded file is kept (Files disk)
 * @property string $status checked | imported | discarded
 * @property int $total_rows
 * @property int $error_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property list<array{row: int, column: string|null, message: string}>|null $errors
 * @property list<array{row: int, action: string, note: string|null, ok: bool, cells: array<string, string>}>|null $preview
 * @property int|null $created_by
 * @property CarbonImmutable|null $imported_at
 * @property CarbonImmutable $created_at
 * @property-read User|null $creator
 */
#[Fillable(['kind', 'filename', 'path', 'status', 'created_by'])]
class Import extends Model
{
    use BelongsToTenant;

    public const CHECKED = 'checked';

    public const IMPORTED = 'imported';

    public const DISCARDED = 'discarded';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::CHECKED,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'total_rows' => 'integer',
            'error_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'errors' => 'array',
            'preview' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validRows(): int
    {
        return max(0, $this->total_rows - $this->error_rows);
    }
}
