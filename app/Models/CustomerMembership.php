<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A contact's role in one organization. Buyers place orders; approvers can
 * also approve them; admins manage the account's contacts. Whether they can
 * charge to the account is separate from the role.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $organization_id
 * @property string $role
 * @property bool $can_charge_account
 */
class CustomerMembership extends Pivot
{
    public const ROLES = ['buyer' => 'Buyer', 'approver' => 'Approver', 'admin' => 'Account admin'];

    protected $table = 'customer_organization';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'can_charge_account' => 'boolean',
        ];
    }
}
