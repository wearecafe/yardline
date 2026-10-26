<?php

namespace App\Audit;

use App\Models\AuditEvent;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the audit log. Never lets a logging problem break the change it
 * describes.
 */
final class Audit
{
    /** Set while a portal customer is acting, so events are theirs. */
    public static ?int $customerId = null;

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes
     */
    public static function record(Model $subject, string $event, ?array $changes = null, ?string $note = null): void
    {
        if (CurrentTenant::id() === null) {
            return;
        }

        try {
            AuditEvent::query()->create([
                'user_id' => Auth::guard('web')->id(),
                'customer_id' => self::$customerId,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => (int) $subject->getKey(),
                'subject_label' => self::label($subject),
                'event' => $event,
                'changes' => $changes,
                'note' => $note,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function label(Model $subject): ?string
    {
        foreach (['number', 'name', 'sku'] as $field) {
            $value = $subject->getAttribute($field);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
