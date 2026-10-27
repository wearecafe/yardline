<?php

namespace App\Access;

use App\Audit\Audit;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Notifications\UserInviteMessage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adding, inviting and deactivating the people who work at a yard, for
 * Settings → Users and Team → Employees.
 *
 * Time & attendance only is held on its own. Everyone else needs an email
 * to sign in; a time-only person may have one (to see their own time) or
 * not (they punch at the counter tablet with their PIN).
 */
final class UserAccounts
{
    /**
     * Rules for a new person's name, email, roles and yards.
     *
     * @param  list<UserRole>|null  $roles  roles that may be given (default: every assignable role)
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = '', ?array $roles = null): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'max:255'],
            "{$prefix}email" => ['nullable', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            ...self::roleRules($prefix, required: true, roles: $roles),
            ...self::yardRules($prefix),
        ];
    }

    /**
     * @param  list<UserRole>|null  $roles
     * @return array<string, mixed>
     */
    public static function roleRules(string $prefix = '', bool $required = true, ?array $roles = null): array
    {
        $roles ??= UserRole::assignable();

        return [
            "{$prefix}roles" => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            "{$prefix}roles.*" => ['string', Rule::in(array_map(fn (UserRole $r) => $r->value, $roles))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function yardRules(string $prefix = ''): array
    {
        return [
            "{$prefix}location_ids" => ['sometimes', 'array'],
            "{$prefix}location_ids.*" => ['integer', TenantRule::exists('locations')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<UserRole>
     */
    public static function roles(array $data): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => UserRole::fromValue((string) $value),
            (array) ($data['roles'] ?? []),
        )));
    }

    /**
     * Time & attendance only stands alone, and anyone who does more than
     * clock in needs an email to sign in with.
     *
     * @param  list<UserRole>  $roles
     */
    public static function ensureRolesFit(array $roles, ?string $email, string $prefix = ''): void
    {
        $timeOnly = in_array(UserRole::TimeOnly, $roles, true);

        if ($timeOnly && count($roles) > 1) {
            throw ValidationException::withMessages(["{$prefix}roles" => 'Time & attendance only can’t be combined with other roles.']);
        }

        if (! $timeOnly && blank($email)) {
            throw ValidationException::withMessages(["{$prefix}email" => 'They need an email to sign in. Only Time & attendance only people can go without one.']);
        }
    }

    /**
     * Add someone to this business, with their yards (every yard unless
     * given), and email them a link to choose a password if they have an
     * email.
     *
     * @param  array<string, mixed>  $data  validated name, email, roles and location_ids
     */
    public static function create(array $data): User
    {
        $roles = self::roles($data);
        $email = filled($data['email'] ?? null) ? (string) $data['email'] : null;
        self::ensureRolesFit($roles, $email);

        $user = DB::transaction(function () use ($data, $roles, $email) {
            $user = new User([
                'tenant_id' => CurrentTenant::id(),
                'name' => $data['name'],
                'email' => $email,
                // Replaced when they choose their own from the emailed link.
                'password' => Hash::make(Str::random(40)),
            ]);
            $user->setRoles($roles)->save();

            Audit::record($user, 'created', ['roles' => [null, self::roleLabels($user)]]);

            $ids = $data['location_ids'] ?? Location::query()->pluck('id')->all();
            $user->setYards(array_map(fn ($id) => (int) $id, (array) $ids));

            return $user;
        });

        self::invite($user);

        return $user;
    }

    /** Email them a link to choose a password, if they can sign in. */
    public static function invite(User $user): bool
    {
        if (! $user->canSignIn()) {
            return false;
        }

        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $user->notify(new UserInviteMessage($url, CurrentTenant::get()->name ?? config('app.name'), self::roleLabels($user)));

        return true;
    }

    /**
     * They no longer work here: they can't sign in, drop out of every
     * list, and stop clocking in. Their hours stay on file with their name.
     */
    public static function deactivate(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['deactivated_at' => now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            Employee::query()->active()->where('user_id', $user->id)->get()
                ->each(fn (Employee $employee) => $employee->forceFill(['archived_at' => now()])->save());

            Audit::record($user, 'deactivated', ['roles' => [self::roleLabels($user), null]]);
        });
    }

    /** Back at work, with the roles and yards they had. */
    public static function reactivate(User $user): void
    {
        if ($user->deactivated_at === null) {
            return;
        }

        $user->forceFill(['deactivated_at' => null])->save();
        Audit::record($user, 'reactivated', ['roles' => [null, self::roleLabels($user)]]);
    }

    public static function roleLabels(User $user): string
    {
        return implode(', ', array_map(fn (UserRole $r) => $r->label(), $user->roleList()));
    }
}
