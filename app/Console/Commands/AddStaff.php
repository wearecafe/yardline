<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Gives someone at Yardline access to the back office (/admin), or takes it
 * away. Staff accounts have no yard of their own. There's deliberately no
 * screen for this: only someone with server access can add staff.
 */
class AddStaff extends Command
{
    protected $signature = 'yardline:staff:add
        {email : Their email address}
        {--name= : Their name (for a new account)}
        {--remove : Take back-office access away instead}';

    protected $description = 'Add (or remove) a Yardline staff account for the back office';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('That isn’t an email address.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($this->option('remove')) {
            if ($user === null || ! $user->isPlatformStaff()) {
                $this->error("{$email} isn’t a staff account.");

                return self::FAILURE;
            }

            $user->forceFill(['is_platform_staff' => false])->save();
            $this->info("{$email} can no longer open the back office.");

            return self::SUCCESS;
        }

        if ($user !== null && $user->tenant_id !== null) {
            $this->error("{$email} already signs in to a yard. Staff need an account of their own.");

            return self::FAILURE;
        }

        if ($user === null) {
            $name = trim((string) $this->option('name'));

            $user = new User;
            $user->forceFill([
                'tenant_id' => null,
                'name' => $name !== '' ? $name : Str::before($email, '@'),
                'email' => $email,
                'role' => UserRole::Staff,
                'email_verified_at' => now(),
                // Replaced when they choose their own from the link below.
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $user->forceFill(['is_platform_staff' => true])->save();

        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $this->info("{$user->email} is Yardline staff.");
        $this->line('Send them this link to choose a password (it expires in 60 minutes):');
        $this->line($url);

        if (config('yardline.back_office.require_two_factor')) {
            $this->line('They’ll be asked to turn on two-factor authentication before the back office opens.');
        }

        return self::SUCCESS;
    }
}
