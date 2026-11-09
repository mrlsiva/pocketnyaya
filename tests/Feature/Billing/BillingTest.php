<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\TaxEngine;
use App\Services\MatterService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Practice\PracticeTestCase;

/** All tax rates here are made-up test values, not tax advice. */
class BillingTest extends PracticeTestCase
{
    private function rule(array $overrides = []): TaxRule
    {
        $rule = new TaxRule($overrides + [
            'code' => 'TEST_'.Str::upper(Str::random(8)), 'name' => 'Test rule', 'sac_code' => '9982',
            'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'intra_state',
            'charge_mechanism' => 'forward', 'cgst_rate' => '9.00', 'sgst_rate' => '9.00', 'igst_rate' => '0.00',
            'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01',
        ]);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();

        return $rule;
    }

    private function draft(string $firm, ?Client $client = null): Invoice
    {
        return app(InvoiceService::class)->createDraft($client ?? $this->client($firm), null, null, null);
    }

    private function issued(string $firm, string $unitPrice = '1000.00'): Invoice
    {
        $invoice = $this->draft($firm);
        app(InvoiceService::class)->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Synthetic fee', 'quantity' => '1', 'unit_price' => $unitPrice]);

        return app(InvoiceService::class)->issue($invoice->fresh());
    }

    // Money

    #[DataProvider('moneyCases')]
    public function test_money_arithmetic_never_uses_floats(string $input, int $paise): void
    {
        $this->assertSame($paise, Money::toPaise($input));
        $this->assertSame(number_format($paise / 100, 2, '.', ''), Money::fromPaise($paise));
    }

    public static function moneyCases(): array
    {
        return [['0.10', 10], ['0.1', 10], ['1234.56', 123456], ['1000', 100000], ['0.07', 7], ['19.99', 1999]];
    }

    public function test_percentages_round_half_up_correctly(): void
    {
        $this->assertSame(9000, Money::percentOf(100000, '9.00'));
        $this->assertSame(9, Money::percentOf(100, '9.00'));
        $this->assertSame(0, Money::percentOf(5, '9.00'));
        $this->assertSame(1, Money::percentOf(6, '9.00'));
        $this->assertSame(0, Money::percentOf(5, '0.00'));
        $this->assertSame(250, Money::multiply('2.5', 100));
        $this->assertSame('₹1,25,000.50', Money::format('125000.50'));
        $this->assertSame('₹999.00', Money::format('999'));
        $this->assertSame('₹1,00,00,000.00', Money::format('10000000'));
    }

    // The tax engine

    public function test_no_approved_rule_blocks_issuing_rather_than_guessing_a_rate(): void
    {
        $firm = $this->firm();
        $invoice = $this->draft($firm);
        app(InvoiceService::class)->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'X', 'quantity' => '1', 'unit_price' => '100']);

        $unapproved = new TaxRule(['code' => 'DRAFT', 'name' => 'Draft', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'any', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'priority' => 100, 'effective_from' => '2020-01-01']);
        $unapproved->save();

        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->issue($invoice->fresh());
    }

    public function test_intra_state_uses_cgst_sgst_and_inter_state_uses_igst(): void
    {
        $this->rule();
        $this->rule(['supply_type' => 'inter_state', 'cgst_rate' => '0', 'sgst_rate' => '0', 'igst_rate' => '18.00']);
        $firm = $this->firm();
        $service = app(InvoiceService::class);

        $local = $this->issued($firm);
        $this->assertSame(['90.00', '90.00', '0.00'], [$local->cgst_amount, $local->sgst_amount, $local->igst_amount]);
        $this->assertSame('1180.00', $local->total_amount);

        $other = new Client(['client_type' => 'individual', 'name' => 'Far', 'state_code' => $this->otherState()]);
        $other->forceFill(['tenant_id' => $firm])->save();
        $invoice = $this->draft($firm, $other);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'X', 'quantity' => '1', 'unit_price' => '1000']);
        $far = $service->issue($invoice->fresh());
        $this->assertSame(['0.00', '0.00', '180.00'], [$far->cgst_amount, $far->sgst_amount, $far->igst_amount]);
    }

    public function test_reverse_charge_adds_no_tax_to_the_invoice_and_exempt_has_none(): void
    {
        $this->rule(['charge_mechanism' => 'reverse', 'recipient_type' => 'business', 'priority' => 5, 'invoice_type' => 'bill_of_supply']);
        $this->rule(['charge_mechanism' => 'exempt', 'cgst_rate' => '0', 'sgst_rate' => '0', 'recipient_type' => 'individual', 'invoice_type' => 'bill_of_supply']);
        $firm = $this->firm();
        $service = app(InvoiceService::class);

        $business = new Client(['client_type' => 'business', 'name' => 'Co', 'state_code' => '33']);
        $business->forceFill(['tenant_id' => $firm])->save();
        $invoice = $this->draft($firm, $business);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'X', 'quantity' => '1', 'unit_price' => '1000']);
        $issued = $service->issue($invoice->fresh());

        $this->assertSame('reverse', $issued->charge_mechanism);
        $this->assertSame('0.00', $issued->total_tax);
        $this->assertSame('1000.00', $issued->total_amount);

        $this->assertSame('exempt', $this->issued($firm)->charge_mechanism);
    }

    public function test_tenant_override_beats_platform_and_priority_decides_between_rules(): void
    {
        $firm = $this->firm();
        $tenant = Tenant::query()->findOrFail($firm);
        $client = $this->client($firm);
        $engine = app(TaxEngine::class);

        $low = $this->rule(['priority' => 1]);
        $this->assertSame($low->id, $engine->ruleFor($tenant, $client, '2026-01-01')->id);
        $high = $this->rule(['priority' => 10]);
        $this->assertSame($high->id, $engine->ruleFor($tenant, $client, '2026-01-01')->id);

        $override = new TaxRule(['code' => 'OVERRIDE', 'name' => 'Ours', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'any', 'charge_mechanism' => 'forward', 'cgst_rate' => '6', 'sgst_rate' => '6', 'igst_rate' => '0', 'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01']);
        $override->forceFill(['tenant_id' => $firm, 'approved_at' => now(), 'is_active' => true])->save();
        $this->assertSame($override->id, $engine->ruleFor($tenant, $client, '2026-01-01')->id);

        $otherFirm = Tenant::query()->findOrFail($this->firm());
        $this->assertSame($high->id, $engine->ruleFor($otherFirm, $this->client($otherFirm->id), '2026-01-01')->id);
    }

    public function test_rule_dates_are_respected(): void
    {
        $firm = Tenant::query()->findOrFail($this->firm());
        $client = $this->client($firm->id);
        $old = $this->rule(['effective_from' => '2020-01-01', 'effective_to' => '2024-12-31']);
        $new = $this->rule(['effective_from' => '2025-01-01']);
        $engine = app(TaxEngine::class);

        $this->assertSame($old->id, $engine->ruleFor($firm, $client, '2024-06-01')->id);
        $this->assertSame($new->id, $engine->ruleFor($firm, $client, '2025-06-01')->id);
    }

    // Invoices

    public function test_issuing_gives_gap_free_numbers_and_drafts_have_none(): void
    {
        $this->rule();
        $firm = $this->firm();
        $draft = $this->draft($firm);
        $this->assertNull($draft->fresh()->invoice_number);

        $this->travelTo('2026-11-06');
        $this->assertSame('INV/2627/1', $this->issued($firm)->invoice_number);
        $this->assertSame('INV/2627/2', $this->issued($firm)->invoice_number);
        $this->assertSame('INV/2627/1', $this->issued($this->firm())->invoice_number);

        $this->travelTo('2027-04-01');
        $this->assertSame('INV/2728/1', $this->issued($firm)->invoice_number);
        $this->travelBack();
    }

    public function test_a_failed_issue_does_not_use_up_a_number(): void
    {
        $firm = $this->firm();
        $invoice = $this->draft($firm);
        app(InvoiceService::class)->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'X', 'quantity' => '1', 'unit_price' => '1']);

        try {
            app(InvoiceService::class)->issue($invoice->fresh());
            $this->fail('Expected no rule to match.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('invoice_series')->where('tenant_id', $firm)->count());
        }

        $this->rule();
        $this->assertStringEndsWith('/1', (string) app(InvoiceService::class)->issue($invoice->fresh())->invoice_number);
    }

    public function test_issued_invoice_freezes_the_rule_and_customer_details(): void
    {
        $rule = $this->rule();
        $firm = $this->firm();
        $issued = $this->issued($firm);
        $originalName = $issued->recipient_snapshot['name'];

        $rule->forceFill(['cgst_rate' => '20.00', 'sgst_rate' => '20.00'])->save();
        $issued->client->update(['name' => 'Renamed Later']);

        $line = $issued->fresh()->lines()->firstOrFail();
        $this->assertSame('9.00', $line->tax_rule_snapshot['cgst_rate']);
        $this->assertSame('9.00', $line->cgst_rate);
        $this->assertSame($originalName, $issued->fresh()->recipient_snapshot['name']);
    }

    public function test_only_drafts_can_change_and_empty_invoices_cannot_issue(): void
    {
        $this->rule();
        $firm = $this->firm();
        $service = app(InvoiceService::class);

        $this->assertThrows(fn () => $service->issue($this->draft($firm)), ValidationException::class);

        $issued = $this->issued($firm);
        $this->assertThrows(fn () => $service->addLine($issued, ['line_type' => 'event', 'description' => 'X', 'quantity' => '1', 'unit_price' => '1']), ValidationException::class);
        $this->assertThrows(fn () => $service->issue($issued), ValidationException::class);
    }

    public function test_database_enforces_the_money_rules(): void
    {
        $firm = $this->firm();
        $invoice = $this->draft($firm);
        $row = DB::table('invoices')->where('id', $invoice->id);

        $rejected = function (array $change, string $check) use ($row) {
            try {
                (clone $row)->update($change);
                $this->fail("Expected {$check}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($check, $e->getMessage());
            }
        };
        $rejected(['total_amount' => '50.00'], 'invoices_chk_5');
        $rejected(['round_off' => '5.00', 'total_amount' => '5.00', 'balance_due' => '5.00'], 'invoices_chk_4');
        $rejected(['subtotal' => '10.00', 'total_amount' => '10.00', 'balance_due' => '99.00'], 'invoices_chk_6');
        $rejected(['invoice_number' => 'BAD NUMBER!'], 'invoices_chk_7');

        $base = ['id' => (string) Str::uuid(), 'tenant_id' => $firm, 'invoice_id' => $invoice->id, 'line_type' => 'event', 'description' => 'X', 'unit_price' => 1, 'amount' => 1, 'line_total' => 1];
        try {
            DB::table('invoice_lines')->insert($base + ['igst_amount' => 1, 'cgst_amount' => 1]);
            $this->fail('A line cannot hold IGST and CGST together.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lines_chk_3', $e->getMessage());
        }
    }

    public function test_invoice_numbers_are_unique_per_firm_at_database_level(): void
    {
        $firm = $this->firm();
        $first = $this->draft($firm);
        $second = $this->draft($firm);
        DB::table('invoices')->where('id', $first->id)->update(['invoice_number' => 'INV/1']);

        $this->expectException(QueryException::class);
        DB::table('invoices')->where('id', $second->id)->update(['invoice_number' => 'INV/1']);
    }

    public function test_cross_firm_references_are_rejected_by_the_database(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();
        $clientA = $this->client($firmA);

        $this->expectException(QueryException::class);
        $invoice = new Invoice;
        $invoice->forceFill(['tenant_id' => $firmB, 'client_id' => $clientA->id, 'place_of_supply' => '33'])->save();
    }

    // Payments

    public function test_part_and_full_payment_with_tds_reconcile(): void
    {
        $this->rule();
        $firm = $this->firm();
        $issued = $this->issued($firm);
        $user = User::factory()->create();
        $service = app(InvoiceService::class);

        $service->recordPayment($issued, $user, ['amount' => '500.00', 'payment_date' => '2026-01-01', 'method' => 'upi']);
        $fresh = $issued->fresh();
        $this->assertSame('partially_paid', $fresh->status);
        $this->assertSame('680.00', $fresh->balance_due);

        $service->recordPayment($fresh, $user, ['amount' => '580.00', 'tds_amount' => '100.00', 'payment_date' => '2026-01-02', 'method' => 'cheque']);
        $paid = $issued->fresh();
        $this->assertSame('paid', $paid->status);
        $this->assertSame('0.00', $paid->balance_due);
        $this->assertSame('1080.00', $paid->amount_paid);
        $this->assertSame('100.00', $paid->tds_deducted);

        $this->assertThrows(fn () => $service->recordPayment($paid, $user, ['amount' => '1', 'payment_date' => '2026-01-03', 'method' => 'cash']), ValidationException::class);
    }

    public function test_overpayment_and_draft_payment_are_refused(): void
    {
        $this->rule();
        $firm = $this->firm();
        $user = User::factory()->create();
        $service = app(InvoiceService::class);

        $this->assertThrows(fn () => $service->recordPayment($this->issued($firm), $user, ['amount' => '1180.01', 'payment_date' => '2026-01-01', 'method' => 'upi']), ValidationException::class);
        $this->assertThrows(fn () => $service->recordPayment($this->draft($firm), $user, ['amount' => '1', 'payment_date' => '2026-01-01', 'method' => 'upi']), ValidationException::class);
    }

    public function test_reversing_a_payment_restores_the_balance_and_only_once(): void
    {
        $this->rule();
        $issued = $this->issued($this->firm());
        $service = app(InvoiceService::class);
        $payment = $service->recordPayment($issued, User::factory()->create(), ['amount' => '1180.00', 'payment_date' => '2026-01-01', 'method' => 'upi']);
        $this->assertSame('paid', $issued->fresh()->status);

        $service->reversePayment($payment);
        $this->assertSame('issued', $issued->fresh()->status);
        $this->assertSame('1180.00', $issued->fresh()->balance_due);

        $this->assertThrows(fn () => $service->reversePayment($payment->fresh()), ValidationException::class);
    }

    public function test_cancel_needs_no_payments_and_keeps_the_number(): void
    {
        $this->rule();
        $firm = $this->firm();
        $service = app(InvoiceService::class);
        $issued = $this->issued($firm);
        $number = $issued->invoice_number;
        $payment = $service->recordPayment($issued, User::factory()->create(), ['amount' => '10.00', 'payment_date' => '2026-01-01', 'method' => 'upi']);

        $this->assertThrows(fn () => $service->cancel($issued->fresh(), 'Mistake'), ValidationException::class);
        $service->reversePayment($payment);
        $service->cancel($issued->fresh(), 'Mistake');

        $fresh = $issued->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame($number, $fresh->invoice_number);
    }

    // Through the screens, with roles

    public function test_full_invoice_journey_on_screen(): void
    {
        $this->rule();
        $firm = $this->firm();
        $client = $this->client($firm);
        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);

        $this->post('/practice/invoices', ['client_id' => $client->id])->assertRedirect();
        $invoice = Invoice::query()->where('tenant_id', $firm)->firstOrFail();
        $this->post("/practice/invoices/{$invoice->id}/lines", ['line_type' => 'professional_fee', 'description' => 'Drafting', 'quantity' => '2', 'unit_price' => '500.50'])->assertRedirect();
        $this->assertSame('1001.00', $invoice->fresh()->subtotal);
        $this->post("/practice/invoices/{$invoice->id}/lines", ['line_type' => 'event', 'description' => 'X', 'quantity' => '-1', 'unit_price' => '1'])->assertSessionHasErrors('quantity');

        $this->post("/practice/invoices/{$invoice->id}/issue")->assertRedirect();
        $issued = $invoice->fresh();
        $this->assertSame('issued', $issued->status);
        $this->get("/practice/invoices/{$invoice->id}")->assertOk()->assertSee((string) $issued->invoice_number)->assertSee('Drafting');

        $this->post("/practice/invoices/{$invoice->id}/payments", ['amount' => '9999999', 'payment_date' => now()->toDateString(), 'method' => 'upi'])->assertSessionHasErrors('amount');
        $this->post("/practice/invoices/{$invoice->id}/payments", ['amount' => '100', 'payment_date' => now()->toDateString(), 'method' => 'upi'])->assertRedirect();
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $this->get('/practice/reports')->assertOk();
    }

    public function test_associates_and_firm_admin_get_no_invoice_access(): void
    {
        $this->rule();
        $firm = $this->firm();
        $invoice = $this->issued($firm);

        foreach (['Senior Associate', 'Junior Associate', 'Clerk / Office Staff', 'Firm Admin'] as $role) {
            $this->inTenant($this->member($firm, $role), $firm);
            $this->get('/practice/invoices')->assertForbidden();
            $this->get("/practice/invoices/{$invoice->id}")->assertForbidden();
            $this->post("/practice/invoices/{$invoice->id}/payments", ['amount' => '1', 'payment_date' => now()->toDateString(), 'method' => 'upi'])->assertForbidden();
        }
    }

    public function test_another_firm_gets_404_on_invoices_and_payments(): void
    {
        $this->rule();
        $firm = $this->firm();
        $invoice = $this->issued($firm);
        $payment = app(InvoiceService::class)->recordPayment($invoice, User::factory()->create(), ['amount' => '1', 'payment_date' => '2026-01-01', 'method' => 'upi']);
        $other = $this->firm();
        $this->inTenant($this->member($other, 'Managing Partner'), $other);

        $this->get("/practice/invoices/{$invoice->id}")->assertNotFound();
        $this->post("/practice/invoices/{$invoice->id}/issue")->assertNotFound();
        $this->post("/practice/invoices/{$invoice->id}/payments", ['amount' => '1', 'payment_date' => now()->toDateString(), 'method' => 'upi'])->assertNotFound();
        $this->post("/practice/payments/{$payment->id}/reverse")->assertNotFound();
        $this->get('/practice/invoices')->assertOk()->assertDontSee((string) $invoice->invoice_number);
    }

    public function test_invoice_cannot_be_created_for_another_firms_client(): void
    {
        $firm = $this->firm();
        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);

        $this->post('/practice/invoices', ['client_id' => $this->client($this->firm())->id])->assertStatus(422);
    }

    // DA-29: billing-restricted matters

    public function test_finance_manager_sees_restricted_invoice_redacted_and_cannot_change_it(): void
    {
        $this->rule();
        $firm = $this->firm();
        $client = $this->client($firm, 'Visible Client Name');
        $matter = $this->matter($firm, $client);
        $matter->forceFill(['title' => 'Confidential Matter Title', 'matter_number' => 'SECRET-NUM'])->save();
        app(MatterService::class)->setBillingRestriction($matter, true);
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, $matter, null, null);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Secret line text', 'quantity' => '1', 'unit_price' => '1000']);
        $service->issue($invoice->fresh());

        $finance = $this->member($firm, 'Finance Manager');
        $this->inTenant($finance, $firm);
        $page = $this->get("/practice/invoices/{$invoice->id}")->assertOk();
        $page->assertSee('Visible Client Name')->assertSee('₹1,180.00')->assertDontSee('Secret line text')->assertDontSee('SECRET-NUM');

        $this->post("/practice/invoices/{$invoice->id}/cancel", ['cancel_reason' => 'X'])->assertForbidden();
        $this->post("/practice/invoices/{$invoice->id}/payments", ['amount' => '10', 'payment_date' => now()->toDateString(), 'method' => 'upi'])->assertRedirect();
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->get("/practice/invoices/{$invoice->id}")->assertOk()->assertSee('Secret line text');
    }

    // Time and expenses

    public function test_time_rules(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $senior = $this->member($firm, 'Senior Associate');
        DB::table('tenant_memberships')->where('id', $this->membership($firm, $junior))->update(['supervisor_membership_id' => $this->membership($firm, $senior)]);
        $matter = $this->matter($firm);
        $this->assign($matter, $junior);
        $other = $this->matter($firm);

        $this->inTenant($junior, $firm);
        $this->post('/practice/time', ['matter_id' => $matter->id, 'work_date' => now()->toDateString(), 'minutes' => 30, 'description' => 'Research'])->assertRedirect();
        $this->post('/practice/time', ['matter_id' => $other->id, 'work_date' => now()->toDateString(), 'minutes' => 30, 'description' => 'X'])->assertNotFound();
        $this->post('/practice/time', ['matter_id' => $matter->id, 'work_date' => now()->toDateString(), 'minutes' => 0, 'description' => 'X'])->assertSessionHasErrors('minutes');
        $entry = TimeEntry::query()->where('tenant_id', $firm)->firstOrFail();
        $this->assertSame($this->membership($firm, $junior), $entry->membership_id);
        $this->post("/practice/time/{$entry->id}/approve")->assertForbidden();

        $this->inTenant($senior, $firm);
        $this->delete("/practice/time/{$entry->id}")->assertNotFound();
        $this->post("/practice/time/{$entry->id}/approve")->assertRedirect();
        $this->assertSame('approved', $entry->fresh()->status);

        $this->inTenant($junior, $firm);
        $this->delete("/practice/time/{$entry->id}")->assertStatus(422);

        $unsupervised = $this->member($firm, 'Senior Associate');
        $entry->forceFill(['status' => 'draft'])->save();
        $this->inTenant($unsupervised, $firm);
        $this->post("/practice/time/{$entry->id}/approve")->assertForbidden();
    }

    public function test_time_list_shows_own_entries_only_without_view_all(): void
    {
        $firm = $this->firm();
        $mine = $this->member($firm, 'Junior Associate');
        $theirs = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);
        $this->assign($matter, $mine);
        $this->assign($matter, $theirs);

        $this->inTenant($theirs, $firm);
        $this->post('/practice/time', ['matter_id' => $matter->id, 'work_date' => now()->toDateString(), 'minutes' => 5, 'description' => 'Their private work'])->assertRedirect();

        $this->inTenant($mine, $firm);
        $this->get('/practice/time')->assertOk()->assertDontSee('Their private work');
        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);
        $this->get('/practice/time')->assertOk()->assertSee('Their private work');
    }

    public function test_expense_rules(): void
    {
        $firm = $this->firm();
        $clerk = $this->member($firm, 'Clerk / Office Staff');
        $matter = $this->matter($firm);
        $this->assign($matter, $clerk, 'clerk');
        $form = ['expense_date' => now()->toDateString(), 'category' => 'Court fee', 'amount' => '250.50'];

        $this->inTenant($clerk, $firm);
        $this->post('/practice/expenses', $form + ['matter_id' => $matter->id])->assertRedirect();
        $this->post('/practice/expenses', $form + ['matter_id' => $this->matter($firm)->id])->assertNotFound();
        $this->post('/practice/expenses', $form + ['matter_id' => $this->matter($this->firm())->id])->assertNotFound();

        $this->inTenant($this->member($firm, 'Firm Admin'), $firm);
        $this->post('/practice/expenses', $form + ['matter_id' => $matter->id])->assertForbidden();
    }

    // Fees and tax rule approval

    public function test_fee_arrangement_permissions(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $form = ['client_id' => $client->id, 'arrangement_type' => 'hourly', 'hourly_rate' => '2500', 'starts_on' => '2026-01-01'];

        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->get('/practice/fees')->assertForbidden();

        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);
        $this->post('/practice/fees', ['arrangement_type' => 'hourly', 'client_id' => $client->id, 'starts_on' => '2026-01-01'])->assertSessionHasErrors('hourly_rate');
        $this->post('/practice/fees', $form)->assertRedirect();
        $this->get('/practice/fees')->assertOk()->assertSee('₹2,500.00');
        $this->post('/practice/fees', ['client_id' => $this->client($this->firm())->id] + $form)->assertStatus(422);
    }

    public function test_tax_rule_drafter_cannot_approve_their_own_rule(): void
    {
        $platform = (string) AccessContext::platformTenantId();
        $make = function () use ($platform) {
            $admin = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
            DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $admin->id, 'status' => 'active']);
            app(RoleAssignmentService::class)->assign($admin, 'Super Admin', $platform);

            return $admin;
        };
        $drafter = $make();
        $approver = $make();

        $this->actingAs($drafter)->withSession(['mfa_passed_for' => $drafter->id]);
        $this->post('/staff/tax-rules', [
            'code' => 'STD_FORWARD', 'name' => 'Standard', 'sac_code' => '9982', 'supplier_registration' => 'registered', 'recipient_type' => 'any',
            'recipient_turnover' => 'any', 'supply_type' => 'intra_state', 'charge_mechanism' => 'forward', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '0',
            'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2026-01-01', 'legal_reference' => 'Test reference',
        ])->assertRedirect();
        $rule = TaxRule::query()->where('code', 'STD_FORWARD')->firstOrFail();
        $this->assertFalse($rule->is_active);

        $this->post("/staff/tax-rules/{$rule->id}/approve")->assertForbidden();

        $this->actingAs($approver)->withSession(['mfa_passed_for' => $approver->id]);
        $this->post("/staff/tax-rules/{$rule->id}/approve")->assertRedirect();
        $fresh = $rule->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame($approver->id, $fresh->approved_by);
    }

    public function test_no_commission_or_lead_tables_exist(): void
    {
        $tables = collect(DB::select('SHOW TABLES'))->map(fn ($t) => array_values((array) $t)[0]);

        $this->assertSame([], $tables->filter(fn ($t) => preg_match('/commission|lead|referral|rating|review|ranking/i', $t) === 1)->values()->all());
    }

    private function otherState(): string
    {
        DB::table('states')->insertOrIgnore(['code' => '29', 'name' => 'Karnataka']);

        return '29';
    }
}
