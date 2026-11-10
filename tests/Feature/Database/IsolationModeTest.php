<?php

namespace Tests\Feature\Database;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\LocalKeyProvider;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Database\Seeders\PrivacySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ScreeningRuleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Practice\PracticeTestCase;

/**
 * The app with the isolation switch on, as the limited account would see it. Data is seeded
 * with full rights; then this connection leaves the privileged list and reads through the v_
 * views, on the same database session so the test's transaction still applies. Staff pages use
 * the platform connection, which shares the session here.
 */
class IsolationModeTest extends PracticeTestCase
{
    private string $firm;

    private string $otherFirm;

    private User $partner;

    private User $customer;

    private User $otherPartner;

    private string $otherMatterId;

    private string $otherClientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceDataSeeder::class, ScreeningRuleSeeder::class, PrivacySeeder::class]);
        Storage::fake('documents');
        config(['pocketnyaya.document_key_dir' => storage_path('framework/testing/keys/'.uniqid())]);
        app(LocalKeyProvider::class)->createVersion();

        $this->firm = $this->firm();
        $this->otherFirm = $this->firm();
        $this->partner = $this->member($this->firm, 'Managing Partner');
        $this->otherPartner = $this->member($this->otherFirm, 'Managing Partner');
        $client = $this->client($this->firm, 'Visible Client');
        $this->matter($this->firm, $client)->forceFill(['title' => 'Visible Matter'])->save();
        $other = $this->client($this->otherFirm, 'Hidden Client');
        $this->otherClientId = $other->id;
        $otherMatter = $this->matter($this->otherFirm, $other);
        $otherMatter->forceFill(['title' => 'Hidden Matter'])->save();
        $this->otherMatterId = $otherMatter->id;

        $this->customer = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($this->customer);
        $vault = new Document(['title' => 'My vault file', 'category' => 'other']);
        $vault->forceFill(['scope' => 'personal', 'owner_user_id' => $this->customer->id])->save();
        app(DocumentVault::class)->addVersion($vault, UploadedFile::fake()->createWithContent('v.pdf', 'SYNTHETIC'), $this->customer);
        $someoneElse = new Document(['title' => 'Someone else file', 'category' => 'other']);
        $someoneElse->forceFill(['scope' => 'personal', 'owner_user_id' => User::factory()->create()->id])->save();

        $this->switchOn();
    }

    protected function tearDown(): void
    {
        DB::connection()->setTablePrefix('');
        File::deleteDirectory((string) config('pocketnyaya.document_key_dir'));
        parent::tearDown();
    }

    private function switchOn(): void
    {
        DB::statement('DELETE FROM pn_privileged_accounts');
        config(['pocketnyaya.db_isolation' => true]);
        DB::connection()->setTablePrefix('v_');
        $platform = DB::connection('platform');
        $platform->setPdo(DB::connection()->getPdo());
        $platform->setReadPdo(DB::connection()->getPdo());
    }

    public function test_practice_pages_work_and_show_only_the_bound_practice(): void
    {
        $this->inTenant($this->partner, $this->firm);
        foreach (['/practice', '/practice/matters', '/practice/clients', '/practice/invoices', '/practice/documents', '/practice/team', '/practice/fees',
            '/practice/time', '/practice/diary', '/practice/calendar', '/practice/settings', '/practice/retainers', '/practice/reports', '/practice/knowledge',
            '/practice/consultations', '/practice/audit', '/practice/matters/new', '/practice/clients/new', '/workspaces', '/profile',
            '/practice/matters/'.DB::connection('platform')->table('matters')->where('tenant_id', $this->firm)->value('id')] as $url) {
            $this->assertLessThan(500, $this->get($url)->status(), "{$url} failed in isolation mode");
        }
        $this->get('/practice/matters')->assertOk()->assertSee('Visible Matter')->assertDontSee('Hidden Matter');
        $this->get('/practice/clients')->assertOk()->assertSee('Visible Client')->assertDontSee('Hidden Client');
        $this->get("/practice/matters/{$this->otherMatterId}")->assertNotFound();
        $this->get("/practice/clients/{$this->otherClientId}")->assertNotFound();

        $this->post('/practice/clients', ['client_type' => 'individual', 'name' => 'Created Through Views', 'state_code' => '33'])->assertRedirect();
        $this->assertTrue(DB::connection('platform')->table('clients')->where(['name' => 'Created Through Views', 'tenant_id' => $this->firm])->exists());
        $this->assertSame(0, DB::table('clients')->count(), 'Once the request ends, the binding is cleared and nothing is visible.');
    }

    public function test_practice_writes_work_through_the_views(): void
    {
        Notification::fake();
        $this->inTenant($this->partner, $this->firm);
        $client = (string) DB::connection('platform')->table('clients')->where(['tenant_id' => $this->firm, 'name' => 'Visible Client'])->value('id');

        $this->post('/practice/matters', ['client_id' => $client, 'title' => 'Matter Made In Isolation', 'matter_type' => 'litigation', 'priority' => 'normal'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/practice/documents', ['title' => 'Firm file in isolation', 'category' => 'other', 'file' => UploadedFile::fake()->createWithContent('f.pdf', '%PDF-1.4 synthetic')])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/practice/team/invitations', ['email' => 'isolated.invite@example.test', 'role' => 'Clerk / Office Staff'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/practice/invoices', ['client_id' => $client])->assertSessionHasNoErrors()->assertRedirect();

        $base = DB::connection('platform');
        $this->assertTrue($base->table('matters')->where(['tenant_id' => $this->firm, 'title' => 'Matter Made In Isolation'])->exists());
        $document = $base->table('documents')->where(['tenant_id' => $this->firm, 'title' => 'Firm file in isolation'])->first();
        $this->assertNotNull($document);
        $this->assertSame(1, $base->table('document_versions')->where('document_id', $document->id)->count());
        $this->assertTrue($base->table('tenant_invitations')->where(['tenant_id' => $this->firm, 'email' => 'isolated.invite@example.test'])->exists());
        $this->assertTrue($base->table('invoices')->where(['tenant_id' => $this->firm, 'status' => 'draft'])->exists());

        // The new file downloads for this practice and not for another.
        $this->get("/practice/documents/{$document->id}/download")->assertOk();
        $this->inTenant($this->otherPartner, $this->otherFirm);
        $this->get("/practice/documents/{$document->id}/download")->assertNotFound();
    }

    public function test_customer_writes_work_through_the_views(): void
    {
        $this->actingAs($this->customer);
        $this->post('/citizen/vault', ['title' => 'Uploaded in isolation', 'category' => 'identity', 'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 synthetic')])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/citizen/forum', ['title' => 'A question asked in isolation mode', 'body' => 'My landlord has not returned my deposit after I moved out.'])->assertSessionHasNoErrors()->assertRedirect();

        $base = DB::connection('platform');
        $this->assertTrue($base->table('documents')->where(['owner_user_id' => $this->customer->id, 'title' => 'Uploaded in isolation', 'scope' => 'personal'])->exists());
        $this->assertTrue($base->table('forum_questions')->where('title', 'A question asked in isolation mode')->exists());
    }

    public function test_customer_pages_see_only_their_own_records(): void
    {
        $this->actingAs($this->customer);
        foreach (['/citizen', '/citizen/vault', '/citizen/consultations', '/citizen/odr', '/citizen/odr/new', '/citizen/privacy', '/citizen/forum/mine',
            '/citizen/forum/ask', '/citizen/advocate-profile', '/citizen/emergency', '/citizen/practice/new', '/workspaces', '/profile', '/practice-exports'] as $url) {
            $this->assertLessThan(500, $this->get($url)->status(), "{$url} failed in isolation mode");
        }
        $this->get('/citizen/vault')->assertOk()->assertSee('My vault file')->assertDontSee('Someone else file');
    }

    public function test_public_pages_and_the_api_work(): void
    {
        foreach (['/', '/advocates', '/forum', '/login', '/register'] as $url) {
            $this->assertLessThan(500, $this->get($url)->status(), "{$url} failed in isolation mode");
        }

        Sanctum::actingAs($this->partner, ['api:read', 'api:write']);
        $this->getJson('/api/v1/me/workspaces')->assertOk();
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => $this->firm])->assertOk()->assertSee('Visible Matter')->assertDontSee('Hidden Matter');
        $this->getJson("/api/v1/matters/{$this->otherMatterId}", ['X-Tenant-ID' => $this->firm])->assertNotFound();
    }

    public function test_staff_pages_use_the_platform_connection(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::connection('platform')->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $admin->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($admin, 'Super Admin', $platform);

        $this->actingAs($admin)->withSession(['mfa_passed_for' => $admin->id]);
        foreach (['/staff', '/staff/admin/tenants', '/staff/admin/users', '/staff/admin/plans', '/staff/admin/team', '/staff/admin/offboarding',
            '/staff/admin/integrations', '/staff/admin/settings', '/staff/admin/reference', '/staff/audit', '/staff/tax-rules', '/staff/odr-templates'] as $url) {
            $this->assertLessThan(500, $this->get($url)->status(), "{$url} failed in isolation mode");
        }
        $this->get('/staff/admin/tenants')->assertOk()->assertSee('Firm '.$this->otherFirm);
        DB::setDefaultConnection('mariadb');
    }
}
