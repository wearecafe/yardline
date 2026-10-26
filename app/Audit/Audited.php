<?php

namespace App\Audit;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs creates, deletes and changes to the fields a model lists in
 * $audited.
 *
 * @mixin Model
 */
trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(fn (Model $model) => Audit::record($model, 'created'));

        static::updated(function (Model $model) {
            $fields = method_exists($model, 'auditedFields') ? $model->auditedFields() : [];
            $changes = [];

            foreach ($fields as $field) {
                if ($model->wasChanged($field)) {
                    $changes[$field] = [self::plain($model->getOriginal($field)), self::plain($model->getAttribute($field))];
                }
            }

            if ($changes !== []) {
                Audit::record($model, 'updated', $changes);
            }
        });

        static::deleted(fn (Model $model) => Audit::record($model, 'deleted'));
    }

    /**
     * @return list<string>
     */
    public function auditedFields(): array
    {
        return static::AUDITED;
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            default => $value,
        };
    }
}
