<?php

namespace Tests\Feature\Practice;

use App\Models\Client;
use App\Models\Hearing;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\User;
use App\Services\HearingService;
use App\Services\NumberSequenceService;
use App\Services\PracticeService;
use App\Services\RoleAssignmentService;
use App\Support\MatterAccess;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class MatterAccessTest extends PracticeTestCase
{
    private function note(Matter $matter, User $author, string $visibility): MatterNote
    {
        $note = new MatterNote(['body' => 'Synthetic note', 'visibility' => $visibility]);
        $note->forceFill(['tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id, 'author_user_id' => $author->id])->save();

        return $note;
    }

    // Tenant boundaries, at every level

    public function test_another_firm_gets_404_on_web_and_api(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();
        $matter = $this->matter($firmA);
        $client = $matter->client;
        $outsider = $this->member($firmB, 'Managing Partner');

        $this->inTenant($outsider, $firmB);
        $this->get("/practice/matters/{$matter->id}")->assertNotFound();
        $this->get("/practice/clients/{$client->id}")->assertNotFound();
        $this->put("/practice/matters/{$matter->id}", ['title' => 'Hacked', 'matter_type' => 'other', 'priority' => 'low'])->assertNotFound();
        $this->delete("/practice/clients/{$client->id}")->assertNotFound();
        $this->assertSame('Synthetic Matter', $matter->fresh()->title);

        Sanctum::actingAs($outsider, ['api:read', 'api:write']);
        $headers = ['X-Tenant-ID' => $firmB];
        $this->getJson("/api/v1/matters/{$matter->id}", $headers)->assertNotFound();
        $this->getJson("/api/v1/clients/{$client->id}", $headers)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/matters', $headers)->assertOk()->json('data'));
    }

    public function test_lists_never_include_another_firms_records(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();
        $this->matter($firmA, $this->client($firmA, 'Secret Client A'));
        $mine = $this->matter($firmB, $this->client($firmB, 'My Client B'));
        $partner = $this->member($firmB, 'Managing Partner');

        $this->inTenant($partner, $firmB);
        $this->get('/practice/clients')->assertOk()->assertSee('My Client B')->assertDontSee('Secret Client A');
        $this->assertSame([$mine->id], MatterAccess::visibleMatters(Matter::query(), $partner)->pluck('id')->all());
    }

    public function test_api_tenant_header_rules(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        Sanctum::actingAs($partner, ['api:read', 'api:write']);

        $this->getJson('/api/v1/matters')->assertStatus(400);
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => 'not-a-uuid'])->assertStatus(400);
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => $this->firm()])->assertForbidden();
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => (string) DB::table('tenants')->where('type', 'platform')->value('id')])->assertForbidden();
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => $firm])->assertOk();
    }

    public function test_database_rejects_cross_tenant_links(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();
        $clientA = $this->client($firmA);
        $matterA = $this->matter($firmA, $clientA);
        $memberB = $this->member($firmB, 'Junior Associate');

        $rejected = function (callable $write, string $fk) {
            try {
                $write();
                $this->fail("Expected {$fk}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($fk, $e->getMessage());
            }
        };

        $rejected(fn () => $this->matter($firmB, $clientA), 'matters_client_fk');
        $rejected(fn () => DB::table('matter_assignments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $firmA, 'matter_id' => $matterA->id, 'membership_id' => $this->membership($firmB, $memberB),
            'role_on_matter' => 'associate', 'assigned_by' => $memberB->id, 'assigned_at' => now(),
        ]), 'assignments_membership_fk');
        $rejected(fn () => DB::table('hearings')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $firmB, 'matter_id' => $matterA->id, 'court_id' => $this->court(), 'hearing_date' => now(),
        ]), 'hearings_matter_fk');
        $rejected(fn () => DB::table('documents')->insert([
            'id' => (string) Str::uuid(), 'scope' => 'tenant', 'tenant_id' => $firmB, 'owner_user_id' => $memberB->id,
            'matter_id' => $matterA->id, 'title' => 'T', 'category' => 'other',
        ]), 'doc_matter_fk');
    }

    // Role visibility (RC-MV)

    public function test_each_role_sees_the_right_matters(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $senior = $this->member($firm, 'Senior Associate');
        $junior = $this->member($firm, 'Junior Associate');
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $admin = $this->member($firm, 'Firm Admin');
        $finance = $this->member($firm, 'Finance Manager');
        $assigned = $this->matter($firm);
        $other = $this->matter($firm);
        $this->assign($assigned, $junior);
        $this->assign($assigned, $clerk, 'clerk');
        setPermissionsTeamId($firm);

        foreach ([[$partner, true, true], [$senior, false, false], [$junior, true, false], [$clerk, true, false], [$admin, false, false], [$finance, false, false]] as [$user, $seesAssigned, $seesOther]) {
            $this->assertSame($seesAssigned, MatterAccess::canView($user, $assigned));
            $this->assertSame($seesOther, MatterAccess::canView($user, $other));
        }
    }

    public function test_senior_associate_sees_team_matters_only(): void
    {
        $firm = $this->firm();
        $senior = $this->member($firm, 'Senior Associate');
        $junior = $this->member($firm, 'Junior Associate');
        DB::table('tenant_memberships')->where('id', $this->membership($firm, $junior))->update(['supervisor_membership_id' => $this->membership($firm, $senior)]);

        $supervised = $this->matter($firm);
        $this->assign($supervised, $junior);

        $group = (string) Str::uuid();
        DB::table('practice_groups')->insert(['id' => $group, 'tenant_id' => $firm, 'name' => 'Group']);
        DB::table('practice_group_members')->insert(['practice_group_id' => $group, 'membership_id' => $this->membership($firm, $senior), 'tenant_id' => $firm]);
        $inGroup = $this->matter($firm);
        $inGroup->forceFill(['practice_group_id' => $group])->save();

        $unrelated = $this->matter($firm);
        setPermissionsTeamId($firm);

        $this->assertTrue(MatterAccess::canView($senior, $supervised));
        $this->assertTrue(MatterAccess::canView($senior, $inGroup));
        $this->assertFalse(MatterAccess::canView($senior, $unrelated));
    }

    public function test_unallocated_matter_is_only_for_allocators(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm, null, 'unallocated');
        $partner = $this->member($firm, 'Managing Partner');
        $senior = $this->member($firm, 'Senior Associate');
        $junior = $this->member($firm, 'Junior Associate');
        setPermissionsTeamId($firm);

        $this->assertTrue(MatterAccess::canView($partner, $matter));
        $this->assertFalse(MatterAccess::canView($senior, $matter));
        $this->assertFalse(MatterAccess::canView($junior, $matter));

        $group = (string) Str::uuid();
        DB::table('practice_groups')->insert(['id' => $group, 'tenant_id' => $firm, 'name' => 'G', 'lead_membership_id' => $this->membership($firm, $senior)]);
        $matter->forceFill(['practice_group_id' => $group])->save();
        $this->assertTrue(MatterAccess::canView($senior, $matter));
    }

    public function test_the_list_agrees_with_the_rule_for_every_role_and_matter(): void
    {
        $firm = $this->firm();
        $users = [];
        foreach (['Managing Partner', 'Firm Admin', 'Senior Associate', 'Junior Associate', 'Finance Manager', 'Clerk / Office Staff'] as $role) {
            $users[] = $this->member($firm, $role);
        }
        [$partner, , $senior, $junior, , $clerk] = $users;
        DB::table('tenant_memberships')->where('id', $this->membership($firm, $junior))->update(['supervisor_membership_id' => $this->membership($firm, $senior)]);

        $matters = [$this->matter($firm), $this->matter($firm, null, 'unallocated'), $this->matter($firm, null, 'closed'), $this->matter($firm), $this->matter($this->firm())];
        $this->assign($matters[0], $junior);
        $this->assign($matters[3], $clerk, 'clerk');
        setPermissionsTeamId($firm);

        $checks = 0;
        foreach ($users as $user) {
            $listed = MatterAccess::visibleMatters(Matter::query(), $user)->pluck('id')->all();
            foreach ($matters as $matter) {
                $this->assertSame(MatterAccess::canView($user, $matter), in_array($matter->id, $listed, true));
                $checks++;
            }
            $clients = MatterAccess::visibleClients(Client::query(), $user)->pluck('id')->all();
            foreach ($matters as $matter) {
                $this->assertSame(MatterAccess::canViewClient($user, $matter->client), in_array($matter->client_id, $clients, true));
            }
        }
        $this->assertSame(30, $checks);
    }

    public function test_client_is_visible_to_assigned_staff_only_through_a_visible_matter(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $junior = $this->member($firm, 'Junior Associate');
        setPermissionsTeamId($firm);

        $this->assertFalse(MatterAccess::canViewClient($junior, $client));
        $this->assign($matter, $junior);
        $this->assertTrue(MatterAccess::canViewClient($junior, $client));
    }

    public function test_firm_admin_and_finance_cannot_open_matter_or_client_screens(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm);

        foreach (['Firm Admin', 'Finance Manager'] as $role) {
            $this->inTenant($this->member($firm, $role), $firm);
            $this->get('/practice/matters')->assertForbidden();
            $this->get('/practice/clients')->assertForbidden();
            $this->get("/practice/matters/{$matter->id}")->assertForbidden();
        }
    }

    // Clients

    public function test_partner_can_add_edit_and_find_a_client_by_phone(): void
    {
        $firm = $this->firm();
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);

        $this->post('/practice/clients', ['client_type' => 'individual', 'name' => 'New Client', 'state_code' => '33', 'phone' => '98765 43210', 'address' => 'Synthetic Street', 'tenant_id' => $this->firm()])->assertRedirect();

        $client = Client::query()->where('name', 'New Client')->firstOrFail();
        $this->assertSame($firm, $client->tenant_id);
        $this->assertSame('98765 43210', $client->phone);

        $raw = DB::table('clients')->where('id', $client->id)->first();
        $this->assertStringNotContainsString('98765', (string) $raw->phone);
        $this->assertStringNotContainsString('Synthetic', (string) $raw->address);

        $this->get('/practice/clients?q=9876543210')->assertOk()->assertSee('New Client');
        $this->get('/practice/clients?q=9999999999')->assertOk()->assertDontSee('New Client');
    }

    public function test_client_validation_and_permissions(): void
    {
        $firm = $this->firm();
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->post('/practice/clients', ['client_type' => 'alien', 'name' => '', 'state_code' => 'XX', 'gstin' => 'BAD', 'pan' => 'BAD'])
            ->assertSessionHasErrors(['client_type', 'name', 'state_code', 'gstin', 'pan']);

        $this->inTenant($this->member($firm, 'Junior Associate'), $firm);
        $this->post('/practice/clients', ['client_type' => 'individual', 'name' => 'X', 'state_code' => '33'])->assertForbidden();
    }

    public function test_client_with_matters_cannot_be_deleted(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $this->matter($firm, $client);
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);

        $this->delete("/practice/clients/{$client->id}")->assertStatus(422);
        $this->assertNotNull($client->fresh());
    }

    public function test_finance_manager_billing_profile_is_separate_from_client_view(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $finance = $this->member($firm, 'Finance Manager');
        setPermissionsTeamId($firm);

        $this->assertTrue(Gate::forUser($finance)->allows('viewBillingProfile', $client));
        $this->assertTrue(Gate::forUser($finance)->allows('updateBillingProfile', $client));
        $this->assertFalse(Gate::forUser($finance)->allows('view', $client));
        $this->assertFalse(Gate::forUser($this->member($firm, 'Senior Associate'))->allows('viewBillingProfile', $client));
    }

    // Matters

    public function test_creating_a_matter_gives_a_sequential_number_and_stays_unallocated(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);

        $data = ['client_id' => $client->id, 'title' => 'First', 'matter_type' => 'litigation', 'priority' => 'high', 'description' => 'Synthetic details', 'status' => 'closed', 'tenant_id' => $this->firm()];
        $this->post('/practice/matters', $data)->assertRedirect();
        $this->post('/practice/matters', ['title' => 'Second'] + $data)->assertRedirect();

        $matters = Matter::query()->where('tenant_id', $firm)->orderBy('matter_number')->get();
        $year = now()->format('Y');
        $this->assertSame(["M-{$year}-00001", "M-{$year}-00002"], $matters->pluck('matter_number')->all());
        $this->assertSame('unallocated', $matters[0]->status);
        $this->assertStringNotContainsString('Synthetic', (string) DB::table('matters')->where('id', $matters[0]->id)->value('description'));
    }

    public function test_each_firm_has_its_own_number_sequence(): void
    {
        $numbers = app(NumberSequenceService::class);
        $firmA = $this->firm();
        $firmB = $this->firm();

        $this->assertSame(1, $numbers->next($firmA, 'matter', '2026'));
        $this->assertSame(2, $numbers->next($firmA, 'matter', '2026'));
        $this->assertSame(1, $numbers->next($firmB, 'matter', '2026'));
        $this->assertSame(1, $numbers->next($firmA, 'matter', '2027'));
        $this->assertSame(1, $numbers->next(null, 'odr_dispute', 'all'));
    }

    public function test_a_matter_cannot_use_another_firms_client(): void
    {
        $firm = $this->firm();
        $foreign = $this->client($this->firm());
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);

        $this->post('/practice/matters', ['client_id' => $foreign->id, 'title' => 'X', 'matter_type' => 'other', 'priority' => 'low'])->assertStatus(422);
    }

    public function test_solo_matter_opens_at_once_with_the_lawyer_as_lead(): void
    {
        $solo = $this->firm('solo');
        $lawyer = $this->member($solo, 'Independent Lawyer');
        $client = $this->client($solo);
        $this->inTenant($lawyer, $solo);

        $this->post('/practice/matters', ['client_id' => $client->id, 'title' => 'Solo', 'matter_type' => 'other', 'priority' => 'low'])->assertRedirect();

        $matter = Matter::query()->where('tenant_id', $solo)->firstOrFail();
        $this->assertSame('open', $matter->status);
        $this->assertTrue(MatterAccess::lead($lawyer, $matter));
    }

    public function test_allocation_opens_the_matter_and_rejects_duplicates_and_outsiders(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm, null, 'unallocated');
        $partner = $this->member($firm, 'Managing Partner');
        $junior = $this->member($firm, 'Junior Associate');
        $outsider = $this->member($this->firm(), 'Junior Associate');
        $this->inTenant($partner, $firm);

        $url = "/practice/matters/{$matter->id}/assignments";
        $this->post($url, ['membership_id' => $this->membership($firm, $junior), 'role_on_matter' => 'associate'])->assertSessionHasNoErrors();
        $this->assertSame('open', $matter->fresh()->status);

        $this->post($url, ['membership_id' => $this->membership($firm, $junior), 'role_on_matter' => 'lead'])->assertSessionHasErrors('membership_id');
        $this->post($url, ['membership_id' => DB::table('tenant_memberships')->where('user_id', $outsider->id)->value('id'), 'role_on_matter' => 'lead'])->assertSessionHasErrors('membership_id');
    }

    public function test_status_rules(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $matter = $this->matter($firm);
        $this->inTenant($partner, $firm);

        $this->post("/practice/matters/{$matter->id}/archive")->assertStatus(422);
        $this->post("/practice/matters/{$matter->id}/close")->assertRedirect();
        $this->assertSame('closed', $matter->fresh()->status);
        $this->put("/practice/matters/{$matter->id}", ['title' => 'No', 'matter_type' => 'other', 'priority' => 'low'])->assertStatus(422);
        $this->post("/practice/matters/{$matter->id}/reopen")->assertRedirect();
        $this->post("/practice/matters/{$matter->id}/close");
        $this->post("/practice/matters/{$matter->id}/archive")->assertRedirect();
        $this->assertSame('archived', $matter->fresh()->status);
        $this->delete("/practice/matters/{$matter->id}")->assertStatus(422);
    }

    public function test_junior_can_update_assigned_matter_but_not_close_or_delete(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $this->inTenant($junior, $firm);

        $this->put("/practice/matters/{$matter->id}", ['title' => 'Updated', 'matter_type' => 'other', 'priority' => 'low'])->assertRedirect();
        $this->assertSame('Updated', $matter->fresh()->title);
        $this->post("/practice/matters/{$matter->id}/close")->assertForbidden();
        $this->delete("/practice/matters/{$matter->id}")->assertForbidden();
    }

    public function test_client_cannot_be_changed_on_an_existing_matter(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm);
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);

        $this->put("/practice/matters/{$matter->id}", ['client_id' => $this->client($firm)->id, 'title' => 'X', 'matter_type' => 'other', 'priority' => 'low'])
            ->assertSessionHasErrors('client_id');
    }

    public function test_only_managing_partner_can_restrict_billing_visibility(): void
    {
        $firm = $this->firm();
        $matter = $this->matter($firm);
        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->assign($matter, auth()->user());
        $this->put("/practice/matters/{$matter->id}/billing-restriction", ['restricted' => 1])->assertForbidden();

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->put("/practice/matters/{$matter->id}/billing-restriction", ['restricted' => 1])->assertRedirect();
        $this->assertTrue($matter->fresh()->isBillingRestricted());
    }

    // Notes (DA-15)

    public function test_private_notes_are_for_the_author_alone(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $partner = $this->member($firm, 'Managing Partner');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $note = $this->note($matter, $junior, 'private');
        setPermissionsTeamId($firm);

        $this->assertTrue(Gate::forUser($junior)->allows('viewNote', [Matter::class, $note]));
        $this->assertFalse(Gate::forUser($partner)->allows('viewNote', [Matter::class, $note]));
        $this->assertSame(404, Gate::forUser($partner)->inspect('viewNote', [Matter::class, $note])->status());
    }

    public function test_note_visibility_by_level(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $lead = $this->member($firm, 'Senior Associate');
        $junior = $this->member($firm, 'Junior Associate');
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $matter = $this->matter($firm);
        $this->assign($matter, $lead, 'lead');
        $this->assign($matter, $junior);
        $this->assign($matter, $clerk, 'clerk');
        $team = $this->note($matter, $partner, 'team');
        $partners = $this->note($matter, $partner, 'partners');
        setPermissionsTeamId($firm);

        $can = fn (User $u, MatterNote $n) => Gate::forUser($u)->allows('viewNote', [Matter::class, $n]);
        $this->assertTrue($can($junior, $team));
        $this->assertFalse($can($clerk, $team));
        $this->assertTrue($can($partner, $partners));
        $this->assertTrue($can($lead, $partners));
        $this->assertFalse($can($junior, $partners));
    }

    public function test_note_screen_hides_private_notes_and_body_is_encrypted(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $junior = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $note = new MatterNote(['body' => 'TOP SECRET THOUGHT', 'visibility' => 'private']);
        $note->forceFill(['tenant_id' => $firm, 'matter_id' => $matter->id, 'author_user_id' => $junior->id])->save();

        $this->assertStringNotContainsString('TOP SECRET', (string) DB::table('matter_notes')->where('id', $note->id)->value('body'));

        $this->inTenant($partner, $firm);
        $this->get("/practice/matters/{$matter->id}")->assertOk()->assertDontSee('TOP SECRET THOUGHT');
        $this->inTenant($junior, $firm);
        $this->get("/practice/matters/{$matter->id}")->assertOk()->assertSee('TOP SECRET THOUGHT');
    }

    public function test_junior_cannot_write_partner_level_notes_or_change_others_notes(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $partner = $this->member($firm, 'Managing Partner');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $theirs = $this->note($matter, $partner, 'team');
        $this->inTenant($junior, $firm);

        $this->post("/practice/matters/{$matter->id}/notes", ['body' => 'x', 'visibility' => 'partners'])->assertForbidden();
        $this->post("/practice/matters/{$matter->id}/notes", ['body' => 'x', 'visibility' => 'team'])->assertRedirect();
        $this->delete("/practice/notes/{$theirs->id}")->assertNotFound();
    }

    // Tasks

    public function test_task_assignee_can_update_status_only_for_their_own_task(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $this->inTenant($junior, $firm);

        $this->post("/practice/matters/{$matter->id}/tasks", ['title' => 'Do it', 'priority' => 'normal', 'assigned_to_membership_id' => $this->membership($firm, $clerk)])->assertRedirect();
        $task = $matter->tasks()->firstOrFail();

        $this->put("/practice/tasks/{$task->id}/status", ['status' => 'done'])->assertNotFound();

        $this->inTenant($clerk, $firm);
        $this->put("/practice/tasks/{$task->id}/status", ['status' => 'done'])->assertRedirect();
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_tasks_cannot_be_assigned_outside_the_firm(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $outsider = $this->member($other = $this->firm(), 'Clerk / Office Staff');
        $matter = $this->matter($firm);
        $this->inTenant($partner, $firm);

        $this->post("/practice/matters/{$matter->id}/tasks", ['title' => 'X', 'priority' => 'normal', 'assigned_to_membership_id' => $this->membership($other, $outsider)])
            ->assertSessionHasErrors('assigned_to_membership_id');
    }

    // Diary

    public function test_recording_an_outcome_schedules_the_next_hearing(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $this->inTenant($junior, $firm);

        $this->post("/practice/matters/{$matter->id}/hearings", ['court_id' => $this->court(), 'hearing_date' => '2030-01-10'])->assertRedirect();
        $hearing = Hearing::query()->where('matter_id', $matter->id)->firstOrFail();

        $this->post("/practice/hearings/{$hearing->id}/outcome", ['status' => 'adjourned', 'next_hearing_date' => '2030-01-05'])->assertSessionHasErrors('next_hearing_date');
        $this->post("/practice/hearings/{$hearing->id}/outcome", ['status' => 'adjourned', 'outcome_summary' => 'Adjourned', 'next_hearing_date' => '2030-02-10'])->assertRedirect();

        $fresh = $hearing->fresh();
        $this->assertSame('adjourned', $fresh->status);
        $next = Hearing::query()->findOrFail($fresh->next_hearing_id);
        $this->assertSame('2030-02-10', $next->hearing_date->toDateString());
        $this->assertSame(1, DB::table('hearing_attendees')->where('hearing_id', $next->id)->count());
    }

    public function test_two_courts_on_one_day_raises_a_conflict_for_that_person_only(): void
    {
        $firm = $this->firm();
        $busy = $this->member($firm, 'Junior Associate');
        $free = $this->member($firm, 'Senior Associate');
        $first = $this->matter($firm);
        $second = $this->matter($firm);
        $service = app(HearingService::class);
        $busyId = $this->membership($firm, $busy);

        $service->create($first, ['court_id' => $this->court(), 'hearing_date' => '2030-03-01'], [$busyId]);
        $hearing = $service->create($second, ['court_id' => $this->court(), 'hearing_date' => '2030-03-01'], [$busyId, $this->membership($firm, $free)]);

        $this->assertSame(1, DB::table('schedule_conflicts')->where('tenant_id', $firm)->count());
        $conflict = DB::table('schedule_conflicts')->first();
        $this->assertSame($busyId, $conflict->membership_id);
        $this->assertSame('different_court_same_day', $conflict->conflict_type);
        $this->assertLessThan(0, strcmp($conflict->hearing_a_id, $conflict->hearing_b_id));

        $service->detectConflicts($hearing);
        $this->assertSame(1, DB::table('schedule_conflicts')->where('tenant_id', $firm)->count());
    }

    public function test_same_court_same_time_is_a_time_overlap_and_different_time_is_fine(): void
    {
        $firm = $this->firm();
        $member = $this->member($firm, 'Junior Associate');
        $id = $this->membership($firm, $member);
        $court = $this->court();
        $service = app(HearingService::class);

        $service->create($this->matter($firm), ['court_id' => $court, 'hearing_date' => '2030-04-01', 'scheduled_time' => '10:30:00'], [$id]);
        $service->create($this->matter($firm), ['court_id' => $court, 'hearing_date' => '2030-04-01', 'scheduled_time' => '14:30:00'], [$id]);
        $this->assertSame(0, DB::table('schedule_conflicts')->where('tenant_id', $firm)->count());

        $service->create($this->matter($firm), ['court_id' => $court, 'hearing_date' => '2030-04-01', 'scheduled_time' => '10:30:00'], [$id]);
        $this->assertSame('time_overlap', DB::table('schedule_conflicts')->where('tenant_id', $firm)->value('conflict_type'));
    }

    public function test_firm_calendar_hides_titles_of_matters_you_cannot_see(): void
    {
        $firm = $this->firm();
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $matter = $this->matter($firm);
        $matter->forceFill(['title' => 'Confidential Takeover'])->save();
        app(HearingService::class)->create($matter, ['court_id' => $this->court(), 'hearing_date' => now()->addDay()->toDateString()], []);

        $this->inTenant($clerk, $firm);
        $this->get('/practice/calendar')->assertOk()->assertSee('Engaged')->assertDontSee('Confidential Takeover');

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->get('/practice/calendar')->assertOk()->assertSee('Confidential Takeover');

        $this->inTenant($this->member($firm, 'Junior Associate'), $firm);
        $this->get('/practice/calendar')->assertForbidden();
    }

    public function test_diary_entries_are_private_to_their_owner(): void
    {
        $firm = $this->firm();
        $owner = $this->member($firm, 'Junior Associate');
        $other = $this->member($firm, 'Managing Partner');
        $this->inTenant($owner, $firm);
        $this->post('/practice/diary', ['entry_type' => 'personal', 'title' => 'Private appointment', 'starts_at' => now()->addHour()->toDateTimeString()])->assertRedirect();
        $this->get('/practice/diary')->assertSee('Private appointment');
        $entry = DB::table('diary_entries')->where('title', 'Private appointment')->value('id');

        $this->inTenant($other, $firm);
        $this->get('/practice/diary')->assertDontSee('Private appointment');
        $this->delete("/practice/diary/{$entry}")->assertNotFound();
    }

    // Practice setup

    public function test_citizen_can_set_up_one_solo_practice_with_an_advocate_profile(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);
        $this->actingAs($user);

        $form = [
            'type' => 'solo', 'name' => 'My Chambers', 'state_code' => '33',
            'bar_council_id' => DB::table('bar_councils')->value('id'), 'enrollment_number' => 'TEST/55/2019',
            'enrollment_date' => '2019-01-01', 'city_id' => DB::table('cities')->value('id'),
        ];
        $this->post('/citizen/practice', $form)->assertRedirect(route('practice.dashboard'));

        $tenant = DB::table('tenants')->where('owner_user_id', $user->id)->first();
        $this->assertSame('solo', $tenant->type);
        $this->assertSame($tenant->id, session('active_tenant_id'));
        $this->assertNotNull($user->fresh()->advocateProfile);
        $this->get('/practice')->assertOk();

        $this->post('/citizen/practice', $form)->assertSessionHasErrors('type');
    }

    public function test_setting_up_a_firm_makes_the_creator_managing_partner_and_reuses_the_profile(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $user = User::factory()->create();
        $profileData = ['bar_council_id' => DB::table('bar_councils')->value('id'), 'enrollment_number' => 'TEST/77/2019', 'enrollment_date' => '2019-01-01', 'city_id' => DB::table('cities')->value('id')];

        $tenant = app(PracticeService::class)->create($user, ['type' => 'firm', 'name' => 'New Firm', 'state_code' => '33'] + $profileData);
        app(PracticeService::class)->create($user, ['type' => 'firm', 'name' => 'Second Firm', 'state_code' => '33']);

        setPermissionsTeamId($tenant->id);
        $this->assertTrue($user->fresh()->hasRole('Managing Partner'));
        $this->assertSame(1, DB::table('advocate_profiles')->where('user_id', $user->id)->count());
    }

    // Mobile API

    public function test_api_covers_clients_matters_notes_and_diary_within_the_tenant(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        Sanctum::actingAs($partner, ['api:read', 'api:write']);
        $h = ['X-Tenant-ID' => $firm];

        $client = $this->postJson('/api/v1/clients', ['client_type' => 'individual', 'name' => 'Api Client', 'state_code' => '33', 'phone' => '9000000000'], $h)
            ->assertCreated()->assertJsonMissingPath('phone_bidx')->json();
        $matter = $this->postJson('/api/v1/matters', ['client_id' => $client['id'], 'title' => 'Api Matter', 'matter_type' => 'other', 'priority' => 'low'], $h)
            ->assertCreated()->assertJsonPath('status', 'unallocated')->json();

        $this->postJson("/api/v1/matters/{$matter['id']}/assignments", ['membership_id' => $this->membership($firm, $partner), 'role_on_matter' => 'lead'], $h)->assertCreated()->assertJsonPath('status', 'open');
        $this->postJson("/api/v1/matters/{$matter['id']}/notes", ['body' => 'Api note', 'visibility' => 'private'], $h)->assertCreated();
        $this->getJson("/api/v1/matters/{$matter['id']}", $h)->assertOk()->assertJsonPath('notes.0.body', 'Api note');

        $hearing = $this->postJson("/api/v1/matters/{$matter['id']}/hearings", ['court_id' => $this->court(), 'hearing_date' => now()->addDay()->toDateString()], $h)->assertCreated()->json();
        $this->assertCount(1, $this->getJson('/api/v1/diary', $h)->assertOk()->json('data'));
        $this->postJson("/api/v1/hearings/{$hearing['id']}/outcome", ['status' => 'attended'], $h)->assertOk()->assertJsonPath('status', 'attended');
        $this->getJson('/api/v1/diary/conflicts', $h)->assertOk();
    }

    public function test_api_permission_and_write_ability_rules(): void
    {
        $firm = $this->firm();
        $h = ['X-Tenant-ID' => $firm];

        Sanctum::actingAs($this->member($firm, 'Firm Admin'), ['api:read', 'api:write']);
        $this->getJson('/api/v1/matters', $h)->assertForbidden();

        Sanctum::actingAs($this->member($firm, 'Managing Partner'), ['api:read']);
        $this->getJson('/api/v1/matters', $h)->assertOk();
        $this->postJson('/api/v1/clients', ['client_type' => 'individual', 'name' => 'X', 'state_code' => '33'], $h)->assertForbidden();
    }

    public function test_removing_someone_from_a_firm_ends_api_access_at_once(): void
    {
        $firm = $this->firm();
        $member = $this->member($firm, 'Managing Partner');
        Sanctum::actingAs($member, ['api:read', 'api:write']);
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => $firm])->assertOk();

        DB::table('tenant_memberships')->where(['tenant_id' => $firm, 'user_id' => $member->id])->update(['status' => 'left']);
        $this->getJson('/api/v1/matters', ['X-Tenant-ID' => $firm])->assertForbidden();
    }
}
