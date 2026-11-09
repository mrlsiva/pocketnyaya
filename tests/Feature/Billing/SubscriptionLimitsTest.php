<?php

namespace Tests\Feature\Billing;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\LocalKeyProvider;
use App\Services\MatterService;
use App\Services\RoleAssignmentService;
use App\Services\SubscriptionLimits;
use App\Services\TeamService;
use App\Support\AccessContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Practice\PracticeTestCase;

/** Plan limits: none without a plan, and a plan stops new additions only. */
class SubscriptionLimitsTest extends PracticeTestCase
{
    private function plan(array $limits): string
    {
        $id = (string) Str::uuid7();
        DB::table('subscription_plans')->insert(['id' => $id, 'code' => 'p_'.Str::lower(Str::random(8)), 'name' => 'Synthetic plan', 'billing_period' => 'monthly', 'features' => '[]', 'limits' => json_encode($limits), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function subscribe(string $tenant, string $plan, string $status = 'active'): void
    {
        DB::table('tenant_subscriptions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'plan_id' => $plan, 'status' => $status, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_no_plan_means_no_limits(): void
    {
        $firm = $this->firm();
        $this->assertNull(app(SubscriptionLimits::class)->currentPlan($firm));
        app(SubscriptionLimits::class)->ensureRoom($firm, 'max_active_matters', 100000);
        $this->assertTrue(true);
    }

    public function test_matter_limit_counts_open_matters_and_reopening(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $this->subscribe($firm, $this->plan(['max_active_matters' => 2]));
        $this->matter($firm);
        $closed = $this->matter($firm, null, 'closed');
        $client = $this->client($firm);
        setPermissionsTeamId($firm);
        $service = app(MatterService::class);

        $service->create($partner, ['client_id' => $client->id, 'title' => 'Second open matter', 'matter_type' => 'litigation', 'priority' => 'normal']);
        $this->assertThrows(fn () => $service->create($partner, ['client_id' => $client->id, 'title' => 'Third', 'matter_type' => 'litigation', 'priority' => 'normal']), ValidationException::class);
        $this->assertThrows(fn () => $service->reopen($closed), ValidationException::class);
        $this->assertSame('closed', $closed->fresh()->status, 'Nothing existing is changed.');
    }

    public function test_member_limit_counts_pending_invitations(): void
    {
        Notification::fake();
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $this->subscribe($firm, $this->plan(['max_members' => 2]));
        setPermissionsTeamId($firm);
        $team = app(TeamService::class);

        $team->invite($partner, $firm, 'first@example.test', 'Clerk / Office Staff');
        $this->assertThrows(fn () => $team->invite($partner, $firm, 'second@example.test', 'Clerk / Office Staff'), ValidationException::class);
    }

    public function test_storage_limit_refuses_an_upload_that_would_go_over(): void
    {
        Storage::fake('documents');
        config(['pocketnyaya.document_key_dir' => storage_path('framework/testing/keys/'.uniqid())]);
        app(LocalKeyProvider::class)->createVersion();
        $firm = $this->firm();
        $owner = User::factory()->create();
        $this->subscribe($firm, $this->plan(['max_storage_mb' => 1]));
        $document = new Document(['title' => 'Big', 'category' => 'other']);
        $document->forceFill(['scope' => 'tenant', 'tenant_id' => $firm, 'owner_user_id' => $owner->id])->save();
        $vault = app(DocumentVault::class);

        $vault->addVersion($document, UploadedFile::fake()->createWithContent('a.pdf', str_repeat('a', 600 * 1024)), $owner);
        $this->assertThrows(fn () => $vault->addVersion($document, UploadedFile::fake()->createWithContent('b.pdf', str_repeat('b', 600 * 1024)), $owner), ValidationException::class);

        // A personal vault never counts against a practice's plan.
        $personal = new Document(['title' => 'Mine', 'category' => 'other']);
        $personal->forceFill(['scope' => 'personal', 'owner_user_id' => $owner->id])->save();
        $vault->addVersion($personal, UploadedFile::fake()->createWithContent('c.pdf', str_repeat('c', 600 * 1024)), $owner);
        File::deleteDirectory((string) config('pocketnyaya.document_key_dir'));
    }

    public function test_cancelled_or_future_plans_do_not_apply(): void
    {
        $firm = $this->firm();
        $this->subscribe($firm, $this->plan(['max_active_matters' => 0]), 'cancelled');
        $this->assertNull(app(SubscriptionLimits::class)->limit($firm, 'max_active_matters'));
    }

    public function test_staff_set_limits_assign_plans_and_practices_see_usage(): void
    {
        $firm = $this->firm();
        $plan = $this->plan([]);
        $admin = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $admin->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($admin, 'Super Admin', $platform);

        $this->actingAs($admin)->withSession(['mfa_passed_for' => $admin->id]);
        $this->put("/staff/admin/plans/{$plan}/limits", ['max_members' => '5', 'max_active_matters' => '', 'max_storage_mb' => '100'])->assertRedirect();
        $this->assertSame(['max_members' => 5, 'max_storage_mb' => 100], json_decode((string) DB::table('subscription_plans')->where('id', $plan)->value('limits'), true));
        $this->put("/staff/admin/tenants/{$firm}/plan", ['plan_id' => $plan])->assertRedirect();
        $this->assertSame(5, app(SubscriptionLimits::class)->limit($firm, 'max_members'));
        $this->put("/staff/admin/tenants/{$firm}/plan", ['plan_id' => ''])->assertRedirect();
        $this->assertNull(app(SubscriptionLimits::class)->currentPlan($firm));
        $this->assertSame(1, DB::table('tenant_subscriptions')->where(['tenant_id' => $firm, 'status' => 'cancelled'])->count(), 'The old plan is kept as a record.');

        setPermissionsTeamId(null);
        $this->put("/staff/admin/tenants/{$firm}/plan", ['plan_id' => $plan]);
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->get('/practice/settings')->assertOk()->assertSee('Synthetic plan')->assertSee('of 5');
    }
}
