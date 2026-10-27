<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Billing\Billing;
use App\Billing\Trial;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Signing up creates a new business (tenant) with the person as its owner,
 * plus a first yard so orders can be taken straight away. The business
 * starts a free trial with every add-on available to switch on.
 *
 * Inviting staff into an existing business is a separate flow (not built yet).
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'business_name' => ['required', 'string', 'max:255'],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $tenant = Tenant::query()->create([
                'name' => $input['business_name'],
                'slug' => $this->uniqueSlug($input['business_name']),
                'status' => TenantStatus::Trial,
                'trial_ends_at' => Trial::endsOn(),
            ]);
            Billing::refresh($tenant);

            CurrentTenant::run($tenant, fn () => Location::query()->create([
                'name' => 'Main yard',
                'code' => 'MAIN',
                'is_default' => true,
            ]));

            return User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $input['name'],
                'email' => $input['email'],
                'role' => UserRole::Owner,
                'password' => $input['password'],
            ]);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'yard';
        $slug = $base;

        for ($i = 2; Tenant::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
