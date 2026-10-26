<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules that only see the current tenant's rows. Use these for
 * every foreign key that arrives in a request.
 */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', CurrentTenant::id());
    }

    public static function unique(string $table, string $column, mixed $ignore = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('tenant_id', CurrentTenant::id());

        return $ignore === null ? $rule : $rule->ignore($ignore);
    }
}
