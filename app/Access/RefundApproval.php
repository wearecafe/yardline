<?php

namespace App\Access;

use App\Audit\Audit;
use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;
use App\Orders\OrderRules;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Refunds are limited per role (Settings → Roles). A refund above the
 * user's limit needs someone with Refund above limit to approve it on the
 * spot, with their password or time-clock PIN. Both people are logged.
 */
final class RefundApproval
{
    /**
     * Check a refund before it happens. Returns who approved it, if
     * someone had to.
     *
     * @param  'goods'|'rental'  $kind
     * @param  array{approver_id?: mixed, approver_secret?: mixed}  $approval
     */
    public static function check(User $user, int $cents, string $kind, array $approval = []): ?User
    {
        if ($user->canRefund($cents, $kind)) {
            return null;
        }

        $limit = $user->refundLimit($kind) ?? 0;
        $over = $limit > 0
            ? 'Refunds over '.Money::format($limit).' need approval from someone who can refund above the limit.'
            : 'Your role can’t refund. Someone who can refund above the limit has to approve it.';

        $approverId = (int) ($approval['approver_id'] ?? 0);
        $secret = (string) ($approval['approver_secret'] ?? '');

        if ($approverId === 0 || $secret === '') {
            OrderRules::fail($over, 'approver_id');
        }

        $approver = User::query()->where('tenant_id', $user->tenant_id)->find($approverId);

        if ($approver === null || $approver->is($user) || ! $approver->hasPermission(Permission::RefundAboveLimit)) {
            OrderRules::fail('Pick someone who can refund above the limit.', 'approver_id');
        }

        $pinMatches = (Employee::forUser($approver)?->checkPin($secret) ?? false);

        if (! $pinMatches && ! Hash::check($secret, $approver->password)) {
            OrderRules::fail('That password or PIN didn’t match.', 'approver_secret');
        }

        return $approver;
    }

    /** Log a refund that needed approval, naming both people. */
    public static function record(Model $subject, User $user, ?User $approver, int $cents): void
    {
        if ($approver === null) {
            return;
        }

        Audit::record($subject, 'refund approved', note: Money::format($cents)." refunded by {$user->name}, above their limit, approved by {$approver->name}");
    }

    /**
     * People in this business who can approve a refund above the limit.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function approvers(User $user): array
    {
        return array_values(User::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->hasPermission(Permission::RefundAboveLimit))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all());
    }
}
