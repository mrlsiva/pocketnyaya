<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\FeeArrangement;
use App\Models\FeeScheduleItem;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Models\Payment;
use App\Models\RetainerPlan;
use App\Models\RetainerWorkItem;
use App\Models\TaxRule;
use App\Models\TimeEntry;
use App\Services\Billing\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Practice\PracticeTestCase;

/** Time, expenses, scheduled fees and retainers on invoices; retainer screens; invoice PDF. Made-up rates only. */
class BillingSourcesTest extends PracticeTestCase
{
    private function rule(): TaxRule
    {
        $rule = new TaxRule([
            'code' => 'TEST_'.Str::upper(Str::random(8)), 'name' => 'Test rule', 'sac_code' => '9982',
            'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'intra_state',
            'charge_mechanism' => 'forward', 'cgst_rate' => '9.00', 'sgst_rate' => '9.00', 'igst_rate' => '0.00',
            'invoice_type' => 'tax_invoice', 'priority' => 0, 'effective_from' => '2020-01-01',
        ]);
        $rule->forceFill(['approved_at' => now(), 'is_active' => true])->save();

        return $rule;
    }

    private function time(Matter $matter, int $minutes, string $status = 'approved', bool $billable = true): TimeEntry
    {
        $membership = (string) DB::table('tenant_memberships')->where('tenant_id', $matter->tenant_id)->value('id');
        $entry = new TimeEntry(['matter_id' => $matter->id, 'work_date' => '2026-10-01', 'minutes' => $minutes, 'description' => 'Synthetic work', 'is_billable' => $billable]);
        $entry->forceFill(['tenant_id' => $matter->tenant_id, 'membership_id' => $membership, 'status' => $status])->save();

        return $entry;
    }

    private function expense(Matter $matter, string $amount, bool $pureAgent = false, bool $reimbursable = true): MatterExpense
    {
        $expense = new MatterExpense(['matter_id' => $matter->id, 'expense_date' => '2026-10-02', 'category' => 'Court fee', 'amount' => $amount, 'is_reimbursable' => $reimbursable, 'is_pure_agent' => $pureAgent]);
        $expense->forceFill(['tenant_id' => $matter->tenant_id])->save();

        return $expense;
    }

    private function arrangement(Client $client, string $type, array $extra = []): FeeArrangement
    {
        $arrangement = new FeeArrangement(['client_id' => $client->id, 'arrangement_type' => $type, 'starts_on' => '2026-01-01'] + $extra);
        $arrangement->forceFill(['tenant_id' => $client->tenant_id, 'status' => 'active'])->save();

        return $arrangement;
    }

    private function scheduleItem(FeeArrangement $arrangement, string $amount): FeeScheduleItem
    {
        $item = new FeeScheduleItem(['event_type' => 'appearance', 'description' => 'One appearance', 'amount' => $amount]);
        $item->forceFill(['tenant_id' => $arrangement->tenant_id, 'fee_arrangement_id' => $arrangement->id])->save();

        return $item;
    }

    public function test_approved_time_becomes_lines_with_exact_paise_and_is_released_again(): void
    {
        $firm = $this->firm();
        $this->member($firm, 'Finance Manager');
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $a = $this->time($matter, 90);
        $b = $this->time($matter, 20);
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, null, null, null);

        $this->assertSame(2, $service->addTimeEntries($invoice, [$a->id, $b->id], '1000.00'));
        // 90 min at 1,000/h = 1,500.00; 20 min = 333.33 (rounded half up in paise).
        $this->assertSame('1833.33', $invoice->fresh()->subtotal);
        $this->assertSame('billed', $a->fresh()->status);
        $this->assertSame('333.33', $b->fresh()->amount);
        $this->assertThrows(fn () => $service->addTimeEntries($invoice->fresh(), [$a->id], '1000'), ValidationException::class);

        $line = $invoice->lines()->where('amount', '1500.00')->firstOrFail();
        $service->removeLine($invoice->fresh(), $line);
        $this->assertSame('approved', $a->fresh()->status);
        $this->assertNull($a->fresh()->invoice_line_id);

        $service->deleteDraft($invoice->fresh());
        $this->assertSame('approved', $b->fresh()->status);
        $this->assertNull($b->fresh()->amount);
    }

    public function test_only_approved_billable_time_for_this_client_and_matter_is_accepted(): void
    {
        $firm = $this->firm();
        $this->member($firm, 'Finance Manager');
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $otherMatter = $this->matter($firm, $client);
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, $matter, null, null);

        foreach ([
            $this->time($matter, 30, 'draft'),
            $this->time($matter, 30, 'approved', false),
            $this->time($this->matter($firm), 30),
            $this->time($otherMatter, 30),
        ] as $refused) {
            $this->assertThrows(fn () => $service->addTimeEntries($invoice->fresh(), [$refused->id], '1000'), ValidationException::class);
        }
        $this->assertSame('0.00', $invoice->fresh()->subtotal);

        $otherFirm = $this->firm();
        $this->member($otherFirm, 'Finance Manager');
        $foreign = $this->time($this->matter($otherFirm), 30);
        $this->assertThrows(fn () => $service->addTimeEntries($invoice->fresh(), [$foreign->id], '1000'), ValidationException::class);
    }

    public function test_cancelling_an_invoice_releases_its_time_and_expenses(): void
    {
        $this->rule();
        $firm = $this->firm();
        $this->member($firm, 'Finance Manager');
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $entry = $this->time($matter, 60);
        $expense = $this->expense($matter, '250.50');
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, null, null, null);
        $service->addTimeEntries($invoice, [$entry->id], '2000');
        $service->addExpenses($invoice->fresh(), [$expense->id]);
        $issued = $service->issue($invoice->fresh());
        $this->assertSame('2250.50', $issued->subtotal);

        $service->cancel($issued, 'Synthetic mistake');
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertNull($expense->fresh()->invoice_line_id);
        $this->assertSame(2, $issued->lines()->count());
    }

    public function test_pure_agent_and_non_reimbursable_expenses_are_refused(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, null, null, null);

        $this->assertThrows(fn () => $service->addExpenses($invoice, [$this->expense($matter, '100', true)->id]), ValidationException::class);
        $this->assertThrows(fn () => $service->addExpenses($invoice, [$this->expense($matter, '100', false, false)->id]), ValidationException::class);
        $this->assertSame(1, $service->addExpenses($invoice, [$this->expense($matter, '99.99')->id]));
        $this->assertSame('99.99', $invoice->fresh()->subtotal);
    }

    public function test_scheduled_fees_and_retainers_add_lines_only_for_their_own_client(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, null, null, null);

        $event = $this->arrangement($client, 'event_based');
        $item = $this->scheduleItem($event, '5000');
        $service->addScheduleItem($invoice, $item, '2');
        $line = $invoice->lines()->firstOrFail();
        $this->assertSame('10000.00', $line->amount);
        $this->assertSame($item->id, $line->fee_schedule_item_id);

        $plan = new RetainerPlan(['name' => 'Creator plan', 'monthly_fee' => '25000']);
        $plan->forceFill(['tenant_id' => $firm, 'is_active' => true])->save();
        $retainer = $this->arrangement($client, 'creator_retainer', ['retainer_plan_id' => $plan->id]);
        $service->addRetainer($invoice->fresh(), $retainer, 'October 2026');
        $this->assertSame('35000.00', $invoice->fresh()->subtotal);
        $this->assertTrue($invoice->lines()->where('description', 'Creator plan for October 2026')->exists());

        $otherClient = $this->client($firm, 'Someone else');
        $this->assertThrows(fn () => $service->addScheduleItem($invoice->fresh(), $this->scheduleItem($this->arrangement($otherClient, 'event_based'), '1')), ValidationException::class);
        $this->assertThrows(fn () => $service->addRetainer($invoice->fresh(), $this->arrangement($client, 'hourly', ['hourly_rate' => '1000']), 'X'), ValidationException::class);
    }

    public function test_invoice_screens_add_time_and_download_a_private_pdf(): void
    {
        $this->rule();
        $firm = $this->firm();
        $client = $this->client($firm, 'PDF Client');
        $matter = $this->matter($firm, $client);
        $finance = $this->member($firm, 'Finance Manager');
        $entry = $this->time($matter, 60);
        $this->arrangement($client, 'hourly', ['hourly_rate' => '1500']);
        $invoice = app(InvoiceService::class)->createDraft($client, null, null, null);

        $this->inTenant($finance, $firm);
        $this->get("/practice/invoices/{$invoice->id}")->assertOk()->assertSee('Add approved time')->assertSee('1500.00');
        $this->post("/practice/invoices/{$invoice->id}/time", ['entries' => [$entry->id], 'hourly_rate' => '1500'])->assertRedirect();
        $this->assertSame('1500.00', $invoice->fresh()->subtotal);
        $this->get("/practice/invoices/{$invoice->id}/pdf")->assertStatus(422);

        $this->post("/practice/invoices/{$invoice->id}/issue")->assertRedirect();
        $number = (string) $invoice->fresh()->invoice_number;
        $pdf = $this->get("/practice/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertStringContainsString($number, (string) $pdf->getContent());
        $this->assertStringContainsString('Rs. 1,770.00', (string) $pdf->getContent());

        $other = $this->firm();
        $this->inTenant($this->member($other, 'Managing Partner'), $other);
        $this->get("/practice/invoices/{$invoice->id}/pdf")->assertNotFound();
        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->get("/practice/invoices/{$invoice->id}/pdf")->assertForbidden();
    }

    public function test_fee_schedule_screens_and_invoiced_fees_are_kept(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $arrangement = $this->arrangement($client, 'event_based');
        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);

        $this->post("/practice/fees/{$arrangement->id}/items", ['event_type' => 'appearance', 'description' => 'Hearing appearance', 'amount' => '7500'])->assertRedirect();
        $item = FeeScheduleItem::query()->where('fee_arrangement_id', $arrangement->id)->firstOrFail();
        $this->get('/practice/fees')->assertOk()->assertSee('Hearing appearance')->assertSee('₹7,500.00');

        app(InvoiceService::class)->addScheduleItem(app(InvoiceService::class)->createDraft($client, null, null, null), $item);
        $this->delete("/practice/fee-items/{$item->id}")->assertStatus(422);

        $spare = $this->scheduleItem($arrangement, '1');
        $this->delete("/practice/fee-items/{$spare->id}")->assertRedirect();
        $this->assertModelMissing($spare);

        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->post("/practice/fees/{$arrangement->id}/items", ['event_type' => 'other', 'description' => 'X', 'amount' => '1'])->assertForbidden();
    }

    public function test_retainer_plans_and_work_follow_the_permission_matrix(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $senior = $this->member($firm, 'Senior Associate');
        $this->assign($matter, $senior);
        $outsider = $this->member($firm, 'Senior Associate');

        $this->inTenant($this->member($firm, 'Finance Manager'), $firm);
        $this->post('/practice/retainers/plans', ['name' => 'Rising', 'monthly_fee' => '15000', 'scope_items' => "Brand deal reviews\nIP filings"])->assertRedirect();
        $plan = RetainerPlan::query()->where('tenant_id', $firm)->firstOrFail();
        $this->assertSame(['Brand deal reviews', 'IP filings'], $plan->scope_items);
        $this->post('/practice/fees', ['client_id' => $client->id, 'arrangement_type' => 'hourly', 'hourly_rate' => '1', 'retainer_plan_id' => $plan->id, 'starts_on' => '2026-01-01'])->assertStatus(422);
        $this->post('/practice/fees', ['client_id' => $client->id, 'arrangement_type' => 'creator_retainer', 'retainer_plan_id' => $plan->id, 'starts_on' => '2026-01-01'])->assertRedirect();
        $arrangement = FeeArrangement::query()->where('retainer_plan_id', $plan->id)->firstOrFail();

        $this->post('/practice/retainers/work', ['fee_arrangement_id' => $arrangement->id, 'matter_id' => $matter->id, 'work_type' => 'brand_deal_review', 'title' => 'Review synthetic brand deal'])->assertRedirect();
        $this->post('/practice/retainers/work', ['fee_arrangement_id' => $arrangement->id, 'matter_id' => $this->matter($firm)->id, 'work_type' => 'other', 'title' => 'X'])->assertStatus(422);
        $item = RetainerWorkItem::query()->where('tenant_id', $firm)->firstOrFail();
        $this->get('/practice/retainers')->assertOk()->assertSee('Review synthetic brand deal')->assertSee('Rising');

        $this->inTenant($senior, $firm);
        $this->get('/practice/retainers')->assertOk()->assertSee('Review synthetic brand deal')->assertDontSee('Add a retainer plan');
        $this->put("/practice/retainers/work/{$item->id}", ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $item->fresh()->status);
        $this->post('/practice/retainers/plans', ['name' => 'X', 'monthly_fee' => '1'])->assertForbidden();

        $this->inTenant($outsider, $firm);
        $this->get('/practice/retainers')->assertOk()->assertDontSee('Review synthetic brand deal');
        $this->put("/practice/retainers/work/{$item->id}", ['status' => 'open'])->assertNotFound();

        $this->inTenant($this->member($firm, 'Firm Admin'), $firm);
        $this->get('/practice/retainers')->assertForbidden();

        $other = $this->firm();
        $this->inTenant($this->member($other, 'Managing Partner'), $other);
        $this->put("/practice/retainers/work/{$item->id}", ['status' => 'open'])->assertNotFound();
        $this->post("/practice/retainers/plans/{$plan->id}/toggle")->assertNotFound();
    }

    public function test_mobile_api_adds_time_and_expenses_and_downloads_the_pdf(): void
    {
        $this->rule();
        $firm = $this->firm();
        $client = $this->client($firm);
        $matter = $this->matter($firm, $client);
        $finance = $this->member($firm, 'Finance Manager');
        $entry = $this->time($matter, 30);
        $expense = $this->expense($matter, '100.00');
        $invoice = app(InvoiceService::class)->createDraft($client, null, null, null);
        Sanctum::actingAs($finance, ['api:read', 'api:write']);
        $headers = ['X-Tenant-ID' => $firm];

        $this->postJson("/api/v1/invoices/{$invoice->id}/time", ['entries' => [$entry->id], 'hourly_rate' => '3000'], $headers)->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoice->id}/expenses", ['expenses' => [$expense->id]], $headers)->assertCreated();
        $this->assertSame('1600.00', $invoice->fresh()->subtotal);
        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf", $headers)->assertStatus(422);

        $this->postJson("/api/v1/invoices/{$invoice->id}/issue", [], $headers)->assertOk();
        $this->get("/api/v1/invoices/{$invoice->id}/pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $other = $this->firm();
        Sanctum::actingAs($this->member($other, 'Managing Partner'), ['api:read', 'api:write']);
        $this->getJson("/api/v1/invoices/{$invoice->id}/pdf", ['X-Tenant-ID' => $other])->assertNotFound();
    }

    public function test_credit_note_reduces_the_balance_with_proportional_tax_and_is_final(): void
    {
        $this->rule();
        $firm = $this->firm();
        $client = $this->client($firm);
        $finance = $this->member($firm, 'Finance Manager');
        $service = app(InvoiceService::class);
        $invoice = $service->createDraft($client, null, null, null);
        $service->addLine($invoice, ['line_type' => 'professional_fee', 'description' => 'Synthetic fee', 'quantity' => '1', 'unit_price' => '1000']);
        $issued = $service->issue($invoice->fresh());
        $this->assertSame('1180.00', $issued->total_amount);

        $this->inTenant($finance, $firm);
        $this->post("/practice/invoices/{$issued->id}/credit-notes", ['amount' => '1180.01', 'reason' => 'Too much'])->assertSessionHasErrors('amount');
        $this->post("/practice/invoices/{$issued->id}/credit-notes", ['amount' => '590', 'reason' => 'Half the work was not done'])->assertRedirect();

        $note = Invoice::query()->where(['original_invoice_id' => $issued->id, 'invoice_type' => 'credit_note'])->firstOrFail();
        $this->assertMatchesRegularExpression('#^CN/\d{4}/1$#', (string) $note->invoice_number);
        $this->assertSame(['500.00', '45.00', '45.00', '590.00'], [$note->subtotal, $note->cgst_amount, $note->sgst_amount, $note->total_amount]);
        $original = $issued->fresh();
        $this->assertSame('590.00', $original->balance_due);
        $this->assertSame('partially_paid', $original->status);

        $creditPayment = Payment::query()->where(['method' => 'credit_note', 'reference_number' => $note->invoice_number])->firstOrFail();
        $this->assertThrows(fn () => $service->reversePayment($creditPayment), ValidationException::class);
        $this->assertThrows(fn () => $service->issueCreditNote($note, $finance, '1', 'X'), ValidationException::class);
        $this->assertThrows(fn () => $service->cancel($original, 'X'), ValidationException::class);

        $this->get("/practice/invoices/{$note->id}")->assertOk()->assertSee('Credit note against')->assertDontSee('Record a payment');
        $this->assertStringContainsString('Credit note', $service->pdf($note->load('lines')));
        $this->get('/practice/reports')->assertOk()->assertSee('₹500.00')->assertSee('₹590.00');

        $this->inTenant($this->member($firm, 'Senior Associate'), $firm);
        $this->post("/practice/invoices/{$issued->id}/credit-notes", ['amount' => '1', 'reason' => 'X'])->assertForbidden();
    }

    public function test_deleting_a_draft_on_screen_releases_its_time(): void
    {
        $firm = $this->firm();
        $client = $this->client($firm);
        $finance = $this->member($firm, 'Finance Manager');
        $entry = $this->time($this->matter($firm, $client), 45);
        $invoice = app(InvoiceService::class)->createDraft($client, null, null, null);
        app(InvoiceService::class)->addTimeEntries($invoice, [$entry->id], '1000');

        $this->inTenant($finance, $firm);
        $this->delete("/practice/invoices/{$invoice->id}")->assertRedirect();
        $this->assertSoftDeleted(Invoice::class, ['id' => $invoice->id]);
        $this->assertSame('approved', $entry->fresh()->status);
    }
}
