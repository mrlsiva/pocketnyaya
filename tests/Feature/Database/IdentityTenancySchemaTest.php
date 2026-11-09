<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Runs against the real schema inside a transaction that is rolled back.
 * Never add RefreshDatabase here: it would drop the development tables.
 */
class IdentityTenancySchemaTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'name' => 'T', 'email' => $email, 'status' => 'active']);

        return $id;
    }

    private function state(): string
    {
        $code = 'Z'.Str::random(1);
        DB::table('states')->insertOrIgnore(['code' => $code, 'name' => 'Test '.$code]);

        return $code;
    }

    private function city(): int
    {
        $code = $this->state();
        $state = DB::table('states')->where('code', $code)->value('id');

        return (int) DB::table('cities')->insertGetId(['state_id' => $state, 'name' => 'C'.Str::random(8)]);
    }

    /** @param array<string, mixed> $extra */
    private function tenant(string $type, ?string $owner = null, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id, 'type' => $type, 'name' => 'T', 'slug' => 't-'.Str::lower(Str::random(12)),
            'owner_user_id' => $owner, 'gst_registration_type' => 'unregistered', 'state_code' => $this->state(),
        ] + $extra);

        return $id;
    }

    private function member(string $tenant, string $user, string $status = 'active'): string
    {
        $id = (string) Str::uuid();
        DB::table('tenant_memberships')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'status' => $status]);

        return $id;
    }

    private function role(string $name, string $scope, bool $requires = true, ?string $team = null): int
    {
        return (int) DB::table('roles')->insertGetId([
            'name' => $name, 'guard_name' => 'web', 'scope' => $scope, 'requires_membership' => $requires, 'team_id' => $team,
        ]);
    }

    private function assign(int $role, string $user, string $team): void
    {
        DB::table('model_has_roles')->insert(['role_id' => $role, 'model_type' => 'user', 'model_id' => $user, 'team_id' => $team]);
    }

    private function assertRejected(callable $write, string $expected): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }
        $this->fail("Expected the database to reject this write with: {$expected}");
    }

    public function test_user_email_and_phone_are_unique_but_may_be_empty_for_many(): void
    {
        $this->user('a@example.test');
        $this->assertRejected(fn () => $this->user('a@example.test'), 'Duplicate entry');

        DB::table('users')->insert([
            ['id' => (string) Str::uuid(), 'name' => 'Erased 1'],
            ['id' => (string) Str::uuid(), 'name' => 'Erased 2'],
        ]);
        $this->assertSame(2, DB::table('users')->whereNull('email')->where('name', 'like', 'Erased%')->count());
    }

    public function test_user_model_stores_lowercase_email_and_a_uuid7_key(): void
    {
        $user = User::factory()->create(['email' => 'MixedCase@Example.TEST']);

        $this->assertSame('mixedcase@example.test', $user->fresh()->email);
        $this->assertSame('7', $user->id[14]);
    }

    public function test_invalid_status_values_are_rejected(): void
    {
        $this->assertRejected(fn () => DB::table('users')->insert([
            'id' => (string) Str::uuid(), 'name' => 'T', 'status' => 'unknown',
        ]), 'users_status_chk');

        $this->assertRejected(fn () => $this->tenant('agency'), 'tenants_type_chk');
    }

    public function test_only_one_platform_tenant_can_exist(): void
    {
        $this->tenant('platform');
        $this->assertRejected(fn () => $this->tenant('platform'), 'Duplicate entry');
    }

    public function test_one_active_solo_tenant_per_owner_and_soft_delete_frees_it(): void
    {
        $owner = $this->user('solo@example.test');
        $first = $this->tenant('solo', $owner);
        $this->assertRejected(fn () => $this->tenant('solo', $owner), 'Duplicate entry');

        DB::table('tenants')->where('id', $first)->update(['deleted_at' => now()]);
        $this->assertNotEmpty($this->tenant('solo', $owner));
    }

    public function test_gstin_is_unique_among_active_tenants(): void
    {
        $this->tenant('firm', null, ['gstin' => '33AAAAA0000A1Z5']);
        $this->assertRejected(fn () => $this->tenant('firm', null, ['gstin' => '33AAAAA0000A1Z5']), 'Duplicate entry');
    }

    public function test_membership_cannot_use_a_branch_from_another_tenant(): void
    {
        $firmA = $this->tenant('firm');
        $firmB = $this->tenant('firm');
        $branch = (string) Str::uuid();
        DB::table('tenant_branches')->insert([
            'id' => $branch, 'tenant_id' => $firmA, 'name' => 'HO', 'state_code' => $this->state(), 'city_id' => $this->city(),
        ]);

        $this->assertRejected(fn () => DB::table('tenant_memberships')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $firmB, 'user_id' => $this->user('x@example.test'), 'branch_id' => $branch,
        ]), 'memberships_branch_fk');
    }

    public function test_a_tenant_has_only_one_head_office(): void
    {
        $firm = $this->tenant('firm');
        $branch = fn () => DB::table('tenant_branches')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $firm, 'name' => 'HO', 'is_head_office' => true,
            'state_code' => $this->state(), 'city_id' => $this->city(),
        ]);
        $branch();
        $this->assertRejected($branch, 'Duplicate entry');
    }

    public function test_only_one_pending_invitation_per_email_and_acceptance_frees_it(): void
    {
        $firm = $this->tenant('firm');
        $by = $this->user('owner@example.test');
        $invite = function () use ($firm, $by) {
            $id = (string) Str::uuid();
            DB::table('tenant_invitations')->insert([
                'id' => $id, 'tenant_id' => $firm, 'email' => 'new@example.test', 'role_name' => 'Junior Associate',
                'invited_by' => $by, 'token_hash' => hash('sha256', $id), 'expires_at' => now()->addDay(),
            ]);

            return $id;
        };

        $first = $invite();
        $this->assertRejected($invite, 'Duplicate entry');
        DB::table('tenant_invitations')->where('id', $first)->update(['accepted_at' => now()]);
        $this->assertNotEmpty($invite());
    }

    public function test_global_role_names_are_unique(): void
    {
        $this->role('Test Role', 'tenant');
        $this->assertRejected(fn () => $this->role('Test Role', 'tenant'), 'Duplicate entry');
    }

    public function test_platform_role_only_in_platform_tenant(): void
    {
        $firm = $this->tenant('firm');
        $user = $this->user('p@example.test');
        $this->member($firm, $user);
        $role = $this->role('Test Platform', 'platform', false);

        $this->assertRejected(fn () => $this->assign($role, $user, $firm), 'PN_RBAC_SCOPE');
    }

    public function test_tenant_role_is_rejected_in_platform_tenant(): void
    {
        $platform = $this->tenant('platform');
        $user = $this->user('q@example.test');
        $this->member($platform, $user);
        $role = $this->role('Test Tenant', 'tenant');

        $this->assertRejected(fn () => $this->assign($role, $user, $platform), 'PN_RBAC_SCOPE');
    }

    public function test_tenant_role_needs_active_membership(): void
    {
        $firm = $this->tenant('firm');
        $user = $this->user('r@example.test');
        $role = $this->role('Test Tenant', 'tenant');

        $this->assertRejected(fn () => $this->assign($role, $user, $firm), 'PN_RBAC_NO_MEMBERSHIP');

        $this->member($firm, $user, 'invited');
        $this->assertRejected(fn () => $this->assign($role, $user, $firm), 'PN_RBAC_NO_MEMBERSHIP');
    }

    public function test_customer_style_platform_role_needs_no_membership(): void
    {
        $platform = $this->tenant('platform');
        $user = $this->user('c@example.test');
        $this->assign($this->role('Test Customer', 'platform', false), $user, $platform);

        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $user)->count());
    }

    public function test_suspended_tenant_cannot_receive_assignments(): void
    {
        $firm = $this->tenant('firm', null, ['status' => 'suspended']);
        $user = $this->user('s@example.test');
        $this->member($firm, $user);

        $this->assertRejected(fn () => $this->assign($this->role('Test Tenant', 'tenant'), $user, $firm), 'PN_RBAC_TENANT_INACTIVE');
    }

    public function test_custom_role_cannot_be_used_in_another_tenant(): void
    {
        $firmA = $this->tenant('firm');
        $firmB = $this->tenant('firm');
        $user = $this->user('t@example.test');
        $this->member($firmB, $user);
        $role = $this->role('Firm A Role', 'tenant', true, $firmA);

        $this->assertRejected(fn () => $this->assign($role, $user, $firmB), 'PN_RBAC_CROSS_TENANT_ROLE');
    }

    public function test_leaving_or_removal_revokes_roles(): void
    {
        $firm = $this->tenant('firm');
        $role = $this->role('Test Tenant', 'tenant');

        foreach (['status' => ['status' => 'left'], 'soft-delete' => ['deleted_at' => now()]] as $case => $change) {
            $user = $this->user("{$case}@example.test");
            $this->member($firm, $user);
            $this->assign($role, $user, $firm);
            DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $user])->update($change);
            $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user)->count(), $case);
        }

        $user = $this->user('hard@example.test');
        $this->member($firm, $user);
        $this->assign($role, $user, $firm);
        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $user])->delete();
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user)->count());
    }

    public function test_role_definition_cannot_change_scope(): void
    {
        $role = $this->role('Test Tenant', 'tenant');

        $this->assertRejected(fn () => DB::table('roles')->where('id', $role)->update(['scope' => 'platform']), 'PN_RBAC_ROLE_IMMUTABLE');
    }

    public function test_custom_role_cannot_belong_to_platform_tenant(): void
    {
        $platform = $this->tenant('platform');

        $this->assertRejected(fn () => $this->role('Bad Role', 'tenant', true, $platform), 'PN_RBAC_ROLE_OWNER');
    }

    public function test_role_cannot_hold_a_permission_of_another_scope(): void
    {
        $role = $this->role('Test Tenant', 'tenant');
        $permission = DB::table('permissions')->insertGetId(['name' => 'test.platform', 'guard_name' => 'web', 'scope' => 'platform']);

        $this->assertRejected(fn () => DB::table('role_has_permissions')->insert([
            'role_id' => $role, 'permission_id' => $permission,
        ]), 'PN_RBAC_PERMISSION_SCOPE');
    }

    public function test_platform_tenant_type_is_immutable(): void
    {
        $platform = $this->tenant('platform');
        $firm = $this->tenant('firm');

        $this->assertRejected(fn () => DB::table('tenants')->where('id', $platform)->update(['type' => 'firm']), 'PN_TENANT_TYPE');
        $this->assertRejected(fn () => DB::table('tenants')->where('id', $firm)->update(['type' => 'platform']), 'PN_TENANT_TYPE');
    }

    public function test_only_users_can_hold_roles(): void
    {
        $platform = $this->tenant('platform');
        $user = $this->user('u@example.test');
        $role = $this->role('Test Customer', 'platform', false);

        $this->assertRejected(fn () => DB::table('model_has_roles')->insert([
            'role_id' => $role, 'model_type' => 'tenant', 'model_id' => $user, 'team_id' => $platform,
        ]), 'mhr_user_only');
    }

    public function test_spatie_assigns_and_reads_a_role_through_the_user_model(): void
    {
        $firm = $this->tenant('firm');
        $user = User::factory()->create();
        $this->member($firm, $user->id);
        $this->role('Test Associate', 'tenant');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($firm);
        $user->assignRole('Test Associate');

        $this->assertTrue($user->fresh()->hasRole('Test Associate'));
        $this->assertSame($firm, DB::table('model_has_roles')->where('model_id', $user->id)->value('team_id'));
        $this->assertSame('user', DB::table('model_has_roles')->where('model_id', $user->id)->value('model_type'));
    }
}
