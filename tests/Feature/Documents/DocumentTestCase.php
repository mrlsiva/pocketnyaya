<?php

namespace Tests\Feature\Documents;

use App\Models\AdvocateProfile;
use App\Models\Document;
use App\Models\State;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Runs against the real schema inside a transaction that is rolled back.
 * Never add RefreshDatabase here: it would drop the development tables.
 * All data is synthetic, and roles are built here because nothing is seeded yet.
 */
abstract class DocumentTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Document permissions per role, from the approved Step 3 register (D3). */
    protected const ROLES = [
        'Independent Lawyer' => ['tenant', true, ['tenant_documents.view', 'tenant_documents.download', 'tenant_documents.upload', 'tenant_documents.manage_general', 'tenant_documents.update', 'tenant_documents.delete', 'tenant_documents.view_privileged', 'tenant_documents.revoke_share', 'tenant_folders.manage', 'shared_documents.view', 'shared_documents.download', 'matters.view_all', 'clients.view_all']],
        'Managing Partner' => ['tenant', true, ['tenant_documents.view', 'tenant_documents.download', 'tenant_documents.upload', 'tenant_documents.manage_general', 'tenant_documents.update', 'tenant_documents.delete', 'tenant_documents.view_privileged', 'tenant_documents.revoke_share', 'tenant_folders.manage', 'shared_documents.view', 'shared_documents.download', 'matters.view_all', 'clients.view_all']],
        'Senior Associate' => ['tenant', true, ['tenant_documents.view', 'tenant_documents.download', 'tenant_documents.upload', 'tenant_documents.manage_general', 'tenant_documents.update', 'tenant_documents.view_privileged', 'tenant_documents.revoke_share', 'tenant_folders.manage', 'shared_documents.view', 'shared_documents.download']],
        'Junior Associate' => ['tenant', true, ['tenant_documents.view', 'tenant_documents.download', 'tenant_documents.upload', 'tenant_documents.update', 'tenant_documents.view_privileged', 'shared_documents.view', 'shared_documents.download']],
        'Clerk' => ['tenant', true, ['tenant_documents.view', 'tenant_documents.download', 'tenant_documents.upload']],
        'Finance Manager' => ['tenant', true, []],
        'Firm Admin' => ['tenant', true, []],
        'Customer' => ['platform', false, ['vault.documents.manage_own', 'vault.documents.share_own', 'vault.folders.manage_own', 'verification.advocate.apply', 'verification.mediator.apply']],
        'Compliance Officer' => ['platform', true, ['verification.documents.view']],
        'Super Admin' => ['platform', true, []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    protected function assertRejected(callable $write, string $expected): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }
        $this->fail("Expected the database to reject this write with: {$expected}");
    }

    protected function tenant(string $type = 'firm', string $status = 'active'): string
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id, 'type' => $type, 'name' => 'Test Tenant', 'slug' => 't-'.Str::lower(Str::random(14)),
            'gst_registration_type' => 'unregistered', 'state_code' => State::factory()->create()->code, 'status' => $status,
        ]);

        return $id;
    }

    protected function platform(): string
    {
        $existing = DB::table('tenants')->where('type', 'platform')->value('id');

        return is_string($existing) ? $existing : $this->tenant('platform');
    }

    protected function role(string $name): int
    {
        [$scope, $requires, $permissions] = static::ROLES[$name];

        $existing = DB::table('roles')->where('name', $name)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $role = Role::query()->create([
            'name' => $name, 'guard_name' => 'web', 'scope' => $scope, 'requires_membership' => $requires, 'team_id' => null,
        ]);
        foreach ($permissions as $permission) {
            $model = Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['scope' => $scope]);
            DB::table('role_has_permissions')->insert(['permission_id' => $model->getKey(), 'role_id' => $role->getKey()]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return (int) $role->getKey();
    }

    protected function grant(User $user, string $roleName, string $tenant, string $membership = 'active'): void
    {
        $role = $this->role($roleName);
        if (static::ROLES[$roleName][1]) {
            DB::table('tenant_memberships')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $user->id, 'status' => $membership,
            ]);
        }
        DB::table('model_has_roles')->insert([
            'role_id' => $role, 'model_type' => 'user', 'model_id' => $user->id, 'team_id' => $tenant,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A user holding a tenant role, with that tenant as the current context. */
    protected function memberOf(string $tenant, string $roleName): User
    {
        $user = User::factory()->create();
        $this->grant($user, $roleName, $tenant);

        return $user;
    }

    protected function customer(): User
    {
        $user = User::factory()->create();
        $this->grant($user, 'Customer', $this->platform());

        return $user;
    }

    protected function officer(): User
    {
        $user = User::factory()->create();
        $this->grant($user, 'Compliance Officer', $this->platform());

        return $user;
    }

    protected function context(?string $tenant): void
    {
        setPermissionsTeamId($tenant);
    }

    /** A verification request owned by a customer, with one document already attached. */
    protected function verificationDocument(string $requestStatus = 'submitted'): Document
    {
        $applicant = $this->customer();
        $profile = AdvocateProfile::factory()->create(['user_id' => $applicant->id]);
        $request = VerificationRequest::factory()->create(['advocate_profile_id' => $profile->id]);
        $document = Document::factory()->forVerification($request)->create();

        if ($requestStatus !== 'submitted') {
            DB::table('verification_requests')->where('id', $request->id)->update(['status' => $requestStatus]);
        }

        return $document->fresh();
    }
}
