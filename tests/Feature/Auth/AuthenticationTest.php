<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\MfaService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Database\Seeders\PlatformSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'TestPassword123';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('states')->insertOrIgnore(['code' => '33', 'name' => 'Tamil Nadu']);
        $this->seed([RoleSeeder::class, PlatformSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    private function account(array $state = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD] + $state);
        app(RoleAssignmentService::class)->assignCustomer($user);

        return $user;
    }

    private function firm(string $type = 'firm', string $status = 'active'): string
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id, 'type' => $type, 'name' => 'Test Firm '.$id, 'slug' => 'f-'.Str::lower(Str::random(14)),
            'gst_registration_type' => 'unregistered', 'state_code' => '33', 'status' => $status,
        ]);

        return $id;
    }

    private function join(User $user, string $tenant, string $role, string $status = 'active'): void
    {
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $user->id, 'status' => $status]);
        app(RoleAssignmentService::class)->assign($user, $role, $tenant);
    }

    // Register

    public function test_web_registration_creates_an_active_customer_and_asks_for_verification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Test Person', 'email' => 'New.Person@Example.TEST',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'consent' => '1',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(AccessContext::citizenCan($user, 'vault.documents.manage_own'));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_validation(): void
    {
        $this->account(['email' => 'taken@example.test']);

        $this->post('/register', ['name' => '', 'email' => 'bad', 'password' => 'short'])
            ->assertSessionHasErrors(['name', 'email', 'password']);
        $this->post('/register', ['name' => 'X', 'email' => 'TAKEN@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'consent' => '1'])
            ->assertSessionHasErrors('email');
    }

    public function test_registration_cannot_set_status_or_gain_staff_power(): void
    {
        Notification::fake();
        $this->post('/register', [
            'name' => 'X', 'email' => 'x@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'consent' => '1',
            'email_verified_at' => now(), 'two_factor_secret' => 'x', 'role' => 'Super Admin',
        ]);

        $user = User::query()->where('email', 'x@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->two_factor_secret);
        $this->assertFalse(AccessContext::isStaff($user));
    }

    // Email verification

    public function test_unverified_user_cannot_reach_workspaces_until_the_signed_link_is_used(): void
    {
        $user = $this->account(['email_verified_at' => null]);

        $this->actingAs($user)->get('/workspaces')->assertRedirect(route('verification.notice'));

        $link = URL::signedRoute('verification.verify', ['id' => $user->id, 'hash' => sha1((string) $user->email)]);
        $this->get($link)->assertRedirect(route('login'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1('other')]))->assertForbidden();
    }

    // Login

    public function test_login_logout_and_session_rotation(): void
    {
        $user = $this->account();

        $this->post('/login', ['email' => strtoupper((string) $user->email), 'password' => self::PASSWORD])
            ->assertRedirect(route('workspaces.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_wrong_password_and_inactive_accounts_are_refused(): void
    {
        $user = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => 'WrongPassword1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $suspended = $this->account(['status' => 'suspended']);
        $this->post('/login', ['email' => $suspended->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_suspended_user_is_signed_out_on_the_next_request(): void
    {
        $user = $this->account();
        $this->actingAs($user)->get('/workspaces')->assertOk();

        $user->forceFill(['status' => 'suspended'])->save();
        $this->get('/workspaces')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->account();
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'WrongPassword1']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/workspaces', '/citizen', '/practice', '/staff', '/profile'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    // Password reset and change

    public function test_password_reset_revokes_tokens_and_sessions(): void
    {
        Notification::fake();
        $user = $this->account();
        $user->createToken('phone', ['api:read', 'api:write']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'BrandNewPass456', 'password_confirmation' => 'BrandNewPass456'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->post('/login', ['email' => $user->email, 'password' => 'BrandNewPass456'])->assertRedirect();
    }

    public function test_forgot_password_does_not_reveal_who_has_an_account(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertSessionHas('status', 'If that email has an account, a reset link is on its way.');
    }

    public function test_changing_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $user = $this->account();
        $user->createToken('phone', ['api:read', 'api:write']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($user)->put('/profile/password', ['current_password' => 'Wrong1234567', 'password' => 'BrandNewPass456', 'password_confirmation' => 'BrandNewPass456'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(1, $user->tokens()->count());

        $this->put('/profile/password', ['current_password' => self::PASSWORD, 'password' => 'BrandNewPass456', 'password_confirmation' => 'BrandNewPass456'])
            ->assertSessionHas('status');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
    }

    public function test_profile_update_cannot_change_email_or_status(): void
    {
        $user = $this->account();
        $email = $user->email;

        $this->actingAs($user)->put('/profile', ['name' => 'New Name', 'locale' => 'ta', 'email' => 'other@example.test', 'status' => 'erased'])
            ->assertSessionHas('status');

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('ta', $fresh->locale);
        $this->assertSame($email, $fresh->email);
        $this->assertSame('active', $fresh->status);
    }

    // Workspaces and tenant context

    public function test_workspaces_list_only_the_users_own_active_memberships(): void
    {
        $user = $this->account();
        $mine = $this->firm();
        $this->join($user, $mine, 'Managing Partner');
        $left = $this->firm();
        $this->join($user, $left, 'Clerk / Office Staff');
        DB::table('tenant_memberships')->where(['tenant_id' => $left, 'user_id' => $user->id])->update(['status' => 'left']);
        $other = $this->firm();

        $response = $this->actingAs($user)->get('/workspaces')->assertOk();

        $response->assertSee('My personal account');
        $response->assertSee("Test Firm {$mine}");
        $response->assertDontSee("Test Firm {$left}");
        $response->assertDontSee("Test Firm {$other}");
        $response->assertDontSee('Platform administration');
    }

    public function test_switching_to_a_member_tenant_opens_it_and_rotates_the_session(): void
    {
        $user = $this->account();
        $firm = $this->firm();
        $this->join($user, $firm, 'Managing Partner');

        $this->actingAs($user)->withSession(['seed' => 1]);
        $before = session()->getId();
        $this->post('/tenant/switch', ['tenant_id' => $firm])->assertRedirect(route('practice.dashboard'));

        $this->assertSame($firm, session('active_tenant_id'));
        $this->assertNotSame($before, session()->getId());
        $this->get('/practice')->assertOk()->assertSee("Test Firm {$firm}");
    }

    public function test_switching_to_a_foreign_platform_or_invalid_tenant_changes_nothing(): void
    {
        $user = $this->account();
        $mine = $this->firm();
        $this->join($user, $mine, 'Managing Partner');
        $this->actingAs($user)->withSession(['active_tenant_id' => $mine]);

        $this->post('/tenant/switch', ['tenant_id' => $this->firm()])->assertForbidden();
        $this->post('/tenant/switch', ['tenant_id' => AccessContext::platformTenantId()])->assertForbidden();
        $this->post('/tenant/switch', ['tenant_id' => 'not-a-uuid'])->assertSessionHasErrors('tenant_id');

        $this->assertSame($mine, session('active_tenant_id'));
    }

    public function test_practice_area_needs_a_chosen_workspace_and_ends_with_the_membership(): void
    {
        $user = $this->account();
        $firm = $this->firm();
        $this->join($user, $firm, 'Managing Partner');

        $this->actingAs($user)->get('/practice')->assertRedirect(route('workspaces.index'));

        $this->withSession(['active_tenant_id' => $firm])->get('/practice')->assertOk();

        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $user->id])->update(['status' => 'left']);
        $this->get('/practice')->assertRedirect(route('workspaces.index'));
        $this->assertNull(session('active_tenant_id'));
    }

    // Staff and MFA

    public function test_non_staff_cannot_enter_staff_area(): void
    {
        $this->actingAs($this->account())->get('/staff')->assertForbidden();
    }

    public function test_staff_must_set_up_and_pass_mfa(): void
    {
        $staff = $this->account();
        $this->join($staff, (string) AccessContext::platformTenantId(), 'Compliance Officer');
        $mfa = app(MfaService::class);

        $this->actingAs($staff)->get('/staff')->assertRedirect(route('mfa.setup'));

        $this->get('/mfa/setup')->assertOk();
        $secret = (string) session('mfa_pending_secret');
        $this->post('/mfa/setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/mfa/setup', ['code' => $mfa->currentCode($secret)])->assertRedirect(route('staff.dashboard'));
        $this->get('/staff')->assertOk();

        // The secret is stored encrypted.
        $this->assertStringNotContainsString($secret, (string) DB::table('users')->where('id', $staff->id)->value('two_factor_secret'));

        // A new session must pass the challenge.
        $this->post('/logout');
        $this->actingAs($staff->fresh())->flushSession();
        $this->get('/staff')->assertRedirect(route('mfa.challenge'));
        $this->post('/mfa/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/mfa/challenge', ['code' => $mfa->currentCode($secret)])->assertRedirect();
        $this->get('/staff')->assertOk();
    }

    public function test_staff_role_without_platform_membership_gets_no_staff_access(): void
    {
        $staff = $this->account();
        $this->join($staff, (string) AccessContext::platformTenantId(), 'Super Admin');
        DB::table('tenant_memberships')->where(['tenant_id' => AccessContext::platformTenantId(), 'user_id' => $staff->id])->update(['status' => 'left']);

        $this->actingAs($staff->fresh())->get('/staff')->assertForbidden();
    }

    // Context kinds (test PM3)

    public function test_a_permission_only_works_in_its_own_context_kind(): void
    {
        $user = $this->account();
        $staff = $this->account();
        $platform = (string) AccessContext::platformTenantId();
        $this->join($staff, $platform, 'Compliance Officer');
        setPermissionsTeamId($platform);

        $this->assertTrue(AccessContext::staffCan($staff, 'verification.documents.view'));
        $this->assertFalse(AccessContext::citizenCan($staff, 'verification.documents.view'));
        $this->assertFalse(AccessContext::staffCan($staff, 'vault.documents.manage_own'));
        $this->assertTrue(AccessContext::citizenCan($staff, 'vault.documents.manage_own'));
        $this->assertFalse(AccessContext::staffCan($user, 'verification.documents.view'));
        $this->assertFalse(AccessContext::citizenCan($user, 'made.up.permission'));
    }

    // Register integrity

    public function test_every_role_and_permission_from_the_register_is_seeded(): void
    {
        $this->assertSame(13, DB::table('roles')->whereNull('team_id')->count());
        $expected = collect(config('pocketnyaya.permissions'))->flatMap(fn ($p) => array_keys($p))->all();
        $this->assertEqualsCanonicalizing($expected, DB::table('permissions')->pluck('name')->all());
    }

    public function test_key_approved_access_decisions_hold_in_the_seeded_roles(): void
    {
        $has = fn (string $role, string $permission) => DB::table('role_has_permissions as rp')
            ->join('roles', 'roles.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('roles.name', $role)->where('p.name', $permission)->exists();

        // DA-1 and DA-5: no tenant content for Super Admin or Firm Admin.
        foreach (['tenant_documents.view', 'matters.view_all', 'clients.view_all'] as $content) {
            $this->assertFalse($has('Firm Admin', $content), $content);
        }
        $this->assertSame(0, DB::table('role_has_permissions as rp')->join('roles', 'roles.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('roles.name', 'Super Admin')->where('p.scope', 'tenant')->count());
        // DA-6: Senior Associate sees their team, not everything.
        $this->assertTrue($has('Senior Associate', 'matters.view_team'));
        $this->assertFalse($has('Senior Associate', 'matters.view_all'));
        // DA-16: associates have no invoice, fee or payment permissions.
        foreach (['Senior Associate', 'Junior Associate'] as $role) {
            foreach (['billing.invoices.view', 'billing.fee_arrangements.view', 'billing.payments.record'] as $billing) {
                $this->assertFalse($has($role, $billing), "{$role} {$billing}");
            }
        }
    }

    // Mobile API

    public function test_api_register_login_me_and_logout(): void
    {
        Notification::fake();

        $registered = $this->postJson('/api/v1/auth/register', [
            'name' => 'Api User', 'email' => 'Api.User@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'consent' => '1', 'device_name' => 'test-phone',
        ])->assertCreated()->assertJsonPath('user.email', 'api.user@example.test')->assertJsonMissingPath('user.password');
        $this->assertNotEmpty($registered->json('token'));

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'api.user@example.test', 'password' => self::PASSWORD])->assertOk();
        $token = (string) $login->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email_verified', false);
        $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'Renamed'])->assertOk()->assertJsonPath('name', 'Renamed');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(1, User::query()->where('email', 'api.user@example.test')->firstOrFail()->tokens()->count());
    }

    public function test_api_token_expires_after_14_days_and_has_read_write_abilities(): void
    {
        $user = $this->account();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();

        $token = $user->tokens()->firstOrFail();
        $this->assertEquals(['api:read', 'api:write'], $token->abilities);
        $this->assertEqualsWithDelta(14, now()->diffInDays($token->expires_at), 0.01);
    }

    public function test_api_refuses_bad_logins_and_missing_tokens_with_json(): void
    {
        $user = $this->account();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'WrongPass1234'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->get('/api/v1/me')->assertUnauthorized();

        $suspended = $this->account(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/login', ['email' => $suspended->email, 'password' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_session_cookie_does_not_authenticate_the_api(): void
    {
        $this->actingAs($this->account(), 'web')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_suspended_token_holder_is_refused(): void
    {
        $user = $this->account(['status' => 'suspended']);
        Sanctum::actingAs($user, ['api:read', 'api:write']);

        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_read_only_token_cannot_write(): void
    {
        Sanctum::actingAs($this->account(), ['api:read']);

        $this->getJson('/api/v1/me')->assertOk();
        $this->patchJson('/api/v1/me', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_workspaces_need_a_verified_email_and_hide_staff(): void
    {
        $unverified = $this->account(['email_verified_at' => null]);
        Sanctum::actingAs($unverified, ['api:read', 'api:write']);
        $this->getJson('/api/v1/me/workspaces')->assertForbidden();

        $user = $this->account();
        $this->join($user, (string) AccessContext::platformTenantId(), 'Compliance Officer');
        $firm = $this->firm();
        $this->join($user, $firm, 'Managing Partner');
        Sanctum::actingAs($user, ['api:read', 'api:write']);

        $kinds = collect($this->getJson('/api/v1/me/workspaces')->assertOk()->json('data'))->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['citizen', 'tenant'], $kinds);
    }
}
