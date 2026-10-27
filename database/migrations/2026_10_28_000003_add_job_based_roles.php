<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job-based roles. Users can hold several roles and have permissions
 * granted or removed on them. Staff becomes Sales and order picker
 * becomes Yard crew, in users and in the settings that list roles.
 *
 * Every role except Owner and Admin now works only its assigned yards,
 * so people moving to Sales start with every yard, as order pickers did.
 */
return new class extends Migration
{
    private const RENAMED = ['staff' => 'sales', 'picker' => 'yard_crew'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Every role they hold; null means just `role`.
            $table->json('roles')->nullable()->after('role');
            // Permission => true (granted) or false (removed), on top of their roles.
            $table->json('permissions')->nullable()->after('roles');
        });

        foreach (self::RENAMED as $old => $new) {
            DB::table('users')->where('role', $old)->update(['role' => $new]);
        }

        // Sales users work every yard they could before.
        $yards = DB::table('locations')->get(['id', 'tenant_id'])->groupBy('tenant_id');

        DB::table('users')
            ->where('role', 'sales')
            ->whereNotNull('tenant_id')
            ->get(['id', 'tenant_id'])
            ->each(function (object $user) use ($yards) {
                foreach ($yards->get($user->tenant_id, collect()) as $yard) {
                    DB::table('location_user')->insertOrIgnore([
                        'tenant_id' => $user->tenant_id,
                        'location_id' => $yard->id,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('tenants')->whereNotNull('settings')->get(['id', 'settings'])->each(function (object $tenant) {
            $settings = json_decode((string) $tenant->settings, true);

            if (! is_array($settings)) {
                return;
            }

            $access = $settings['access'] ?? ['role_permissions' => [], 'refund_limits' => []];

            // Who could price exempt products is now the Discount exempt
            // products permission, given by role.
            if (array_key_exists('exempt_price_roles', $settings['pricing'] ?? [])) {
                $exempt = $this->rename((array) $settings['pricing']['exempt_price_roles']);

                if (in_array('admin', $exempt, true)) {
                    $access['role_permissions']['admin'] = ['set_manual_prices', 'discount_exempt', 'override_credit', 'refund_above_limit', 'see_costs', 'approve_timesheets', 'manage_users'];
                }

                unset($settings['pricing']['exempt_price_roles']);
            }

            // Admins seeing the profit report is now See gross-profit P&L.
            if (! empty($settings['reports']['admins_can_view_profit'])) {
                $admin = $access['role_permissions']['admin'] ?? ['set_manual_prices', 'override_credit', 'refund_above_limit', 'see_costs', 'approve_timesheets', 'manage_users'];
                $access['role_permissions']['admin'] = array_values(array_unique([...$admin, 'see_profit']));
            }

            unset($settings['reports']);

            foreach (['picked', 'loaded'] as $stage) {
                if (isset($settings['picking']['checks'][$stage]['roles'])) {
                    $settings['picking']['checks'][$stage]['roles'] = $this->rename((array) $settings['picking']['checks'][$stage]['roles']);
                }
            }

            if ($access !== ['role_permissions' => [], 'refund_limits' => []]) {
                $settings['access'] = $access;
            }

            DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        foreach (self::RENAMED as $old => $new) {
            DB::table('users')->where('role', $new)->update(['role' => $old]);
        }

        // Roles that didn't exist before go back to staff.
        DB::table('users')
            ->whereIn('role', ['rental_desk', 'dispatcher', 'purchasing', 'accounts', 'service_tech'])
            ->update(['role' => 'staff']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['roles', 'permissions']);
        });
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<string>
     */
    private function rename(array $roles): array
    {
        return array_values(array_unique(array_map(
            fn ($role) => self::RENAMED[(string) $role] ?? (string) $role,
            $roles,
        )));
    }
};
