<?php

namespace Tests\Feature\Platform;

use App\Models\Document;
use App\Models\LegalHold;
use App\Models\Tenant;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Notifications\PracticeExportReadyNotification;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\LocalKeyProvider;
use App\Services\OffboardingService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Practice\PracticeTestCase;
use ZipArchive;

/** Closing a practice: two people, suspension, holds, export, retention, and no deletion. */
class OffboardingTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['pocketnyaya.document_key_dir' => storage_path('framework/testing/keys/'.uniqid())]);
        app(LocalKeyProvider::class)->createVersion();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('pocketnyaya.document_key_dir'));
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $user->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($user, 'Super Admin', $platform);

        return $user;
    }

    private function as(User $user): static
    {
        setPermissionsTeamId(null);

        return $this->actingAs($user)->withSession(['mfa_passed_for' => $user->id]);
    }

    /** A firm with a partner, an associate who also belongs to another firm, a client and an encrypted document. */
    private function firmWithData(): array
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $associate = $this->member($firm, 'Senior Associate');
        $otherFirm = $this->firm();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $otherFirm, 'user_id' => $associate->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($associate, 'Junior Associate', $otherFirm);
        app(RoleAssignmentService::class)->assignCustomer($associate);
        $client = $this->client($firm, 'Export Client Name');
        $matter = $this->matter($firm, $client);
        $document = new Document(['title' => 'Firm pleading', 'category' => 'other']);
        $document->forceFill(['scope' => 'tenant', 'tenant_id' => $firm, 'owner_user_id' => $partner->id])->save();
        app(DocumentVault::class)->addVersion($document, UploadedFile::fake()->createWithContent('pleading.pdf', 'SYNTHETIC-PLEADING'), $partner);

        return [$firm, $partner, $associate, $otherFirm, $matter];
    }

    public function test_full_closure_keeps_data_and_leaves_people_untouched(): void
    {
        [$firm, $partner, $associate, $otherFirm, $matter] = $this->firmWithData();
        $first = $this->superAdmin();
        $second = $this->superAdmin();

        $this->as($first)->post("/staff/admin/tenants/{$firm}/offboarding", ['reason' => 'Firm dissolved, synthetic', 'recipient_id' => $associate->id])->assertSessionHasErrors('recipient');
        $this->post("/staff/admin/tenants/{$firm}/offboarding", ['reason' => 'Firm dissolved, synthetic', 'recipient_id' => $partner->id])->assertRedirect();
        $closure = TenantOffboarding::query()->where('tenant_id', $firm)->firstOrFail();
        $this->post("/staff/admin/tenants/{$firm}/offboarding", ['reason' => 'Again', 'recipient_id' => $partner->id])->assertSessionHasErrors('reason');

        $this->post("/staff/admin/offboarding/{$closure->id}", ['action' => 'approve'])->assertSessionHasErrors('status');
        $this->assertSame('active', Tenant::query()->find($firm)->status);

        $this->as($second)->post("/staff/admin/offboarding/{$closure->id}", ['action' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('suspended', Tenant::query()->find($firm)->status);
        $this->assertFalse(AccessContext::isActiveMember($partner, $firm), 'Access stops as soon as the closure is approved.');
        $this->put("/staff/admin/tenants/{$firm}", ['status' => 'active', 'reason' => 'X'])->assertStatus(422);

        $this->post("/staff/admin/offboarding/{$closure->id}", ['action' => 'retention'])->assertSessionHasErrors('status');
        $this->post("/staff/admin/offboarding/{$closure->id}", ['action' => 'export'])->assertRedirect();
        Notification::assertSentTo($partner, PracticeExportReadyNotification::class);

        // Only the named recipient can download, and staff can't.
        $this->get("/practice-exports/{$closure->id}/download")->assertNotFound();
        $this->actingAs($associate)->get("/practice-exports/{$closure->id}/download")->assertNotFound();
        $this->actingAs($partner)->get('/practice-exports')->assertOk()->assertSee('Download ZIP');
        $response = $this->actingAs($partner)->get("/practice-exports/{$closure->id}/download")->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()));
        $this->assertStringContainsString('Export Client Name', (string) $zip->getFromName('records/clients.json'));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame(1, $manifest['records']['matters']);
        $this->assertSame('SYNTHETIC-PLEADING', $zip->getFromName($manifest['documents'][0]['file']));
        $zip->close();

        $this->as($second)->post("/staff/admin/offboarding/{$closure->id}", ['action' => 'retention'])->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $closure->fresh();
        $this->assertSame('retention', $fresh->status);
        $this->assertSame('blocked_no_rule', $fresh->disposal_status);
        $tenant = Tenant::withTrashed()->find($firm);
        $this->assertSame('closed', $tenant->status);
        $this->assertTrue($tenant->trashed());

        // Nothing deleted, and nobody's personal account or other firm affected.
        $this->assertNotNull($matter->fresh());
        $this->assertSame(1, Document::query()->where('tenant_id', $firm)->count());
        $this->assertSame('left', DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $associate->id])->value('status'));
        $this->assertSame('active', DB::table('tenant_memberships')->where(['tenant_id' => $otherFirm, 'user_id' => $associate->id])->value('status'));
        $this->assertTrue(AccessContext::isActiveMember($associate, $otherFirm));
        $this->assertTrue(AccessContext::hasPermissionIn($associate, 'forum.questions.create', AccessContext::platformTenantId()));
        $this->assertSame('active', $associate->fresh()->status);
        $this->assertSame(5, DB::table('audit_logs')->where('auditable_id', $firm)->where('event', 'like', 'offboarding.%')->count());

        $this->assertThrows(fn () => TenantOffboarding::query()->whereKey($closure->id)->delete(), QueryException::class);
    }

    public function test_a_hold_is_recorded_and_blocks_disposal(): void
    {
        [$firm, $partner, , , $matter] = $this->firmWithData();
        $hold = new LegalHold(['holdable_type' => 'matter', 'holdable_id' => $matter->id, 'reason' => 'Synthetic']);
        $hold->forceFill(['placed_by' => $partner->id, 'placed_at' => now()])->save();
        $service = app(OffboardingService::class);
        $closure = $service->request($this->superAdmin(), Tenant::query()->findOrFail($firm), 'Synthetic', $partner);
        $service->approve($this->superAdmin(), $closure);
        $this->assertSame(1, $closure->fresh()->active_holds_at_check);

        $service->waiveExport($this->superAdmin(), $closure, 'Firm already holds copies');
        $service->startRetention($this->superAdmin(), $closure->fresh());
        $this->assertSame('blocked_hold', $closure->fresh()->disposal_status);
    }

    public function test_cancel_restores_access_and_staff_without_permission_are_refused(): void
    {
        [$firm, $partner] = $this->firmWithData();
        $service = app(OffboardingService::class);
        $closure = $service->request($this->superAdmin(), Tenant::query()->findOrFail($firm), 'Synthetic', $partner);
        $service->approve($this->superAdmin(), $closure);
        $this->assertFalse(AccessContext::isActiveMember($partner, $firm));

        $service->cancel($this->superAdmin(), $closure->fresh(), 'The firm changed its mind');
        $this->assertSame('active', Tenant::query()->find($firm)->status);
        $this->assertTrue(AccessContext::isActiveMember($partner, $firm));
        $this->assertThrows(fn () => $service->startRetention($this->superAdmin(), $closure->fresh()), ValidationException::class);

        $officer = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $officer->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($officer, 'Compliance Officer', $platform);
        $this->as($officer)->get('/staff/admin/offboarding')->assertForbidden();
        $this->post("/staff/admin/tenants/{$firm}/offboarding", ['reason' => 'X', 'recipient_id' => $partner->id])->assertForbidden();
    }

    public function test_export_expires_and_the_platform_tenant_cannot_be_closed(): void
    {
        [$firm, $partner] = $this->firmWithData();
        $service = app(OffboardingService::class);
        $closure = $service->request($this->superAdmin(), Tenant::query()->findOrFail($firm), 'Synthetic', $partner);
        $service->approve($this->superAdmin(), $closure);
        $service->openExport($this->superAdmin(), $closure->fresh());
        $closure->fresh()->forceFill(['export_expires_at' => now()->subMinute()])->save();
        $this->actingAs($partner)->get("/practice-exports/{$closure->id}/download")->assertStatus(410);

        $this->as($this->superAdmin())->get('/staff/admin/tenants/'.AccessContext::platformTenantId().'/offboarding')->assertNotFound();
    }
}
