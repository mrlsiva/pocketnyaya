<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Services\StaffTeamService;
use App\Support\AccessContext;
use Database\Seeders\PlatformSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** D9 staff role rules: two-person Super Admin approval, a floor of two, and no self-elevation. */
class StaffTeamTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('states')->insertOrIgnore(['code' => '33', 'name' => 'Tamil Nadu']);
        $this->seed([RoleSeeder::class, PlatformSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Start from a clean staff list, so counts in this test are its own.
        $platform = (string) AccessContext::platformTenantId();
        DB::table('model_has_roles')->where('team_id', $platform)->whereIn('role_id', DB::table('roles')->whereIn('name', ['Super Admin', 'Compliance Officer'])->pluck('id'))->delete();
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $user->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($user, $role, $platform);

        return $user;
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['mfa_passed_for' => $user->id]);
    }

    public function test_super_admin_needs_a_proposal_and_a_different_approver(): void
    {
        $first = $this->staff('Super Admin');
        $second = $this->staff('Super Admin');
        $candidate = User::factory()->create();
        $team = app(StaffTeamService::class);

        $this->as($first)->post('/staff/admin/team/super-admin-proposals', ['email' => $candidate->email])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/staff/admin/team/super-admin-proposals', ['email' => $candidate->email])->assertSessionHasErrors('email');
        $this->post("/staff/admin/team/super-admin-proposals/{$candidate->id}", ['decision' => 'approve'])->assertSessionHasErrors('proposal');
        $this->assertFalse($team->holds($candidate, 'Super Admin'));
        $this->get('/staff/admin/team')->assertOk()->assertSee($candidate->email)->assertSee('Waiting for another Super Admin');

        $this->as($second)->post("/staff/admin/team/super-admin-proposals/{$candidate->id}", ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($team->holds($candidate, 'Super Admin'));
        $this->assertTrue(AccessContext::isStaff($candidate->fresh()));
        $this->assertNull($team->openProposal($candidate));
        $this->assertSame(1, DB::table('audit_logs')->where(['event' => 'superadmin.approved', 'auditable_id' => $candidate->id])->count());
    }

    public function test_no_self_proposal_and_unverified_or_suspended_accounts_are_refused(): void
    {
        $first = $this->staff('Super Admin');
        $team = app(StaffTeamService::class);

        $this->assertThrows(fn () => $team->proposeSuperAdmin($first, $first), ValidationException::class);
        $this->assertThrows(fn () => $team->proposeSuperAdmin($first, User::factory()->unverified()->create()), ValidationException::class);
        $this->assertThrows(fn () => $team->grantComplianceOfficer($first, User::factory()->create(['status' => 'suspended'])), ValidationException::class);
    }

    public function test_a_withdrawn_proposal_cannot_be_approved(): void
    {
        $first = $this->staff('Super Admin');
        $second = $this->staff('Super Admin');
        $candidate = User::factory()->create();
        $team = app(StaffTeamService::class);

        $team->proposeSuperAdmin($first, $candidate);
        $team->withdrawProposal($first, $candidate);
        $this->assertThrows(fn () => $team->approveSuperAdmin($second, $candidate), ValidationException::class);
        $this->assertFalse($team->holds($candidate, 'Super Admin'));
    }

    public function test_super_admins_never_fall_below_two(): void
    {
        $first = $this->staff('Super Admin');
        $second = $this->staff('Super Admin');
        $third = $this->staff('Super Admin');
        $team = app(StaffTeamService::class);
        $this->assertSame(3, $team->activeSuperAdminCount());

        $this->assertThrows(fn () => $team->revokeSuperAdmin($first, $first), ValidationException::class);
        $this->as($first)->post("/staff/admin/team/super-admins/{$third->id}/revoke")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $team->activeSuperAdminCount());
        $this->post("/staff/admin/team/super-admins/{$second->id}/revoke")->assertSessionHasErrors('email');
        $this->assertTrue($team->holds($second, 'Super Admin'));
    }

    public function test_compliance_officer_grant_and_revoke_and_others_are_refused(): void
    {
        $admin = $this->staff('Super Admin');
        $candidate = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($candidate);

        $this->as($admin)->post('/staff/admin/team/compliance-officers', ['email' => $candidate->email, 'action' => 'grant'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(AccessContext::isStaff($candidate->fresh()));
        $this->assertTrue(app(StaffTeamService::class)->holds($candidate, 'Compliance Officer'));
        $this->post('/staff/admin/team/compliance-officers', ['email' => 'nobody@example.test', 'action' => 'grant'])->assertSessionHasErrors('email');

        $this->as($this->staff('Compliance Officer'))->get('/staff/admin/team')->assertForbidden();
        $this->post('/staff/admin/team/super-admin-proposals', ['email' => $candidate->email])->assertForbidden();

        $this->as($admin)->post('/staff/admin/team/compliance-officers', ['email' => $candidate->email, 'action' => 'revoke'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(AccessContext::isStaff($candidate->fresh()));
        $this->assertTrue(DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')->where(['r.model_id' => $candidate->id, 'roles.name' => 'Customer'])->exists(), 'The person keeps their customer account.');
    }

    public function test_customers_cannot_reach_the_staff_team_page(): void
    {
        $customer = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($customer);

        $this->actingAs($customer)->get('/staff/admin/team')->assertForbidden();
    }
}
