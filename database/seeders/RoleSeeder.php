<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Builds global roles and permissions from config/pocketnyaya.php. Safe to re-run.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roleIds = [];
        foreach ((array) config('pocketnyaya.roles') as $code => [$name, $scope, $requiresMembership]) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web', 'team_id' => null],
                ['scope' => $scope, 'requires_membership' => $requiresMembership, 'updated_at' => now(), 'created_at' => now()]
            );
            $roleIds[$code] = (int) DB::table('roles')->where('name', $name)->whereNull('team_id')->value('id');
        }

        foreach ((array) config('pocketnyaya.permissions') as $kind => $permissions) {
            $scope = $kind === 'tenant' ? 'tenant' : 'platform';
            foreach ((array) $permissions as $permission => $grantedTo) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $permission, 'guard_name' => 'web'],
                    ['scope' => $scope, 'updated_at' => now(), 'created_at' => now()]
                );
                $id = (int) DB::table('permissions')->where('name', $permission)->value('id');

                foreach (explode(',', (string) $grantedTo) as $code) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleIds[$code]]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
