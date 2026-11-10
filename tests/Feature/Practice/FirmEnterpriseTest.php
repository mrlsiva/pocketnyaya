<?php

namespace Tests\Feature\Practice;

use App\Models\TaxRule;
use App\Models\TimeEntry;
use App\Services\AllocationService;
use App\Services\Billing\InvoiceService;
use App\Services\MatterService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** PRD 2.4: allocation by availability and specialisation, and firm financial reports. */
class FirmEnterpriseTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_allocation_matrix_ranks_by_availability_capacity_and_area(): void
    {
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $onLeave = $this->member($firm, 'Senior Associate');
        $full = $this->member($firm, 'Senior Associate');
        $specialist = $this->member($firm, 'Junior Associate');
        $area = (int) DB::table('practice_areas')->value('id');
        $matter = $this->matter($firm);
        $matter->forceFill(['practice_area_id' => $area])->save();

        DB::table('membership_availability')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $firm, 'membership_id' => $this->membership($firm, $onLeave), 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString(), 'availability' => 'leave']);
        DB::table('tenant_memberships')->where('id', $this->membership($firm, $full))->update(['max_active_matters' => 1]);
        $this->assign($this->matter($firm), $full);

        $this->inTenant($specialist, $firm);
        $this->post('/practice/settings/practice-areas', ['practice_area_ids' => [$area]])->assertRedirect();

        $names = app(AllocationService::class)->candidates($matter)->pluck('name')->all();
        $this->assertSame([$specialist->name, $partner->name, $full->name, $onLeave->name], $names);
        $rows = app(AllocationService::class)->candidates($matter)->keyBy('name');
        $this->assertTrue($rows[$specialist->name]->matches_area);
        $this->assertTrue($rows[$full->name]->at_capacity);
        $this->assertSame('leave', $rows[$onLeave->name]->availability);

        $this->inTenant($partner, $firm);
        $this->get("/practice/matters/{$matter->id}")->assertOk()->assertSee('Allocation matrix')->assertSee('area match')->assertSee('(away)');
    }

    public function test_only_members_managers_set_workload_limits(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->put("/practice/team/{$junior->id}/capacity", ['max_active_matters' => 4])->assertRedirect();
        $this->assertSame(4, (int) DB::table('tenant_memberships')->where('user_id', $junior->id)->value('max_active_matters'));
        $this->put("/practice/team/{$junior->id}/capacity", ['max_active_matters' => ''])->assertRedirect();
        $this->assertNull(DB::table('tenant_memberships')->where('user_id', $junior->id)->value('max_active_matters'));

        $this->inTenant($junior, $firm);
        $this->put("/practice/team/{$junior->id}/capacity", ['max_active_matters' => 99])->assertForbidden();

        $other = $this->firm();
        $this->inTenant($this->member($other, 'Managing Partner'), $other);
        $this->put("/practice/team/{$junior->id}/capacity", ['max_active_matters' => 1])->assertNotFound();
    }

    public function test_reports_show_hours_utilisation_and_fees_by_lead_without_leaking_restricted_matters(): void
    {
        $rule = new TaxRule(['code' => 'T_'.Str::random(6), 'name' => 'Test', 'sac_code' => '9982', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'intra_state', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01']);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();
        $firm = $this->firm();
        $lead = $this->member($firm, 'Senior Associate');
        $client = $this->client($firm);
        $open = $this->matter($firm, $client);
        $open->forceFill(['title' => 'Open matter'])->save();
        $this->assign($open, $lead, 'lead');
        $secret = $this->matter($firm, $client);
        $this->assign($secret, $lead, 'lead');
        app(MatterService::class)->setBillingRestriction($secret, true);

        foreach ([[90, true], [30, false]] as [$minutes, $billable]) {
            $entry = new TimeEntry(['matter_id' => $open->id, 'work_date' => now()->toDateString(), 'minutes' => $minutes, 'description' => 'Synthetic', 'is_billable' => $billable]);
            $entry->forceFill(['tenant_id' => $firm, 'membership_id' => $this->membership($firm, $lead), 'status' => 'approved'])->save();
        }
        $service = app(InvoiceService::class);
        foreach ([[$open, '1000'], [$secret, '5000']] as [$matter, $price]) {
            $invoice = $service->createDraft($client, $matter, null, null);
            $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Fee', 'quantity' => '1', 'unit_price' => $price]);
            $service->issue($invoice->fresh());
        }

        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);
        $page = $this->get('/practice/reports')->assertOk();
        $page->assertSee($lead->name)->assertSee('2h 0m')->assertSee('1h 30m')->assertSee('75%');
        $page->assertSee('₹1,000.00')->assertSee('Restricted matters (combined)')->assertSee('₹5,000.00');

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->get('/practice/reports')->assertOk()->assertDontSee('Restricted matters (combined)')->assertSee('₹6,000.00');
        $this->get('/practice/reports?fy=1999')->assertNotFound();
    }
}
