<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsentPurpose;
use App\Models\DataBreachIncident;
use App\Models\DataPrincipalRequest;
use App\Models\Disclaimer;
use App\Models\LegalHold;
use App\Models\RetentionRule;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\ErasureService;
use App\Services\RetentionService;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** D8.12 staff actions. Staff see request metadata, never consent evidence. */
class ComplianceController extends Controller
{
    public function __construct(private AuditLogWriter $audit) {}

    // Data principal requests

    public function requests(Request $request): View
    {
        return view('staff.privacy.requests', ['requests' => DataPrincipalRequest::query()->with('user:id,name,email')
            ->whereNotIn('status', $request->boolean('all') ? [] : ['completed', 'rejected', 'withdrawn'])->orderBy('due_at')->paginate(25)->withQueryString()]);
    }

    public function processRequest(Request $request, DataPrincipalRequest $privacyRequest, ErasureService $erasure): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        abort_if($privacyRequest->user_id === $staff->id, 403, 'You cannot process your own request.');
        $data = $request->validate([
            'action' => ['required', Rule::in(['verify_identity', 'start_review', 'approve', 'reject', 'complete'])],
            'decision_reason' => ['nullable', 'required_if:action,reject', 'string', 'max:2000'],
        ]);

        $allowed = [
            'verify_identity' => ['submitted', 'identity_verification'],
            'start_review' => ['identity_verification'],
            'approve' => ['in_review'],
            'reject' => ['submitted', 'identity_verification', 'in_review'],
            'complete' => ['approved'],
        ];
        abort_unless(in_array($privacyRequest->status, $allowed[$data['action']], true), 422, 'That step is not available for this request.');

        DB::transaction(function () use ($data, $privacyRequest, $staff, $erasure) {
            match ((string) $data['action']) {
                'verify_identity' => $privacyRequest->forceFill(['status' => 'identity_verification', 'identity_verified_at' => now(), 'verified_by' => $staff->id])->save(),
                'start_review' => $privacyRequest->forceFill(['status' => 'in_review', 'reviewer_id' => $staff->id])->save(),
                'approve' => $privacyRequest->forceFill(['status' => 'approved', 'reviewer_id' => $staff->id, 'decision_reason' => $data['decision_reason'] ?? null])->save(),
                'reject' => $privacyRequest->forceFill(['status' => 'rejected', 'reviewer_id' => $staff->id, 'decision_reason' => $data['decision_reason'], 'completed_at' => now()])->save(),
                'complete' => $privacyRequest->request_type === 'erasure'
                    ? $erasure->execute($privacyRequest)
                    : $privacyRequest->forceFill(['status' => 'completed', 'completed_at' => now()])->save(),
                default => null,
            };
            $this->audit->record('privacy.request_'.$data['action'], 'data_principal_request', $privacyRequest->id, null, null, ['status' => $privacyRequest->status]);
        });

        return back()->with('status', 'Request updated.');
    }

    // Retention rules

    public function retention(): View
    {
        return view('staff.privacy.retention', [
            'rules' => RetentionRule::query()->orderBy('data_category')->orderByDesc('version')->get(),
            'batches' => DB::table('retention_batches')->whereIn('status', ['report', 'proposed', 'executed'])->orderByDesc('reported_at')->limit(50)->get(),
        ]);
    }

    public function storeRetention(Request $request): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate([
            'data_category' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'retention_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'trigger_event' => ['required', Rule::in(['account_closure', 'matter_closure', 'financial_year_end', 'creation', 'settlement_execution', 'request_closure', 'tenant_closure'])],
            'on_expiry' => ['required', Rule::in(['delete', 'anonymize', 'archive', 'review'])],
            'on_erasure_request' => ['required', Rule::in(['erase', 'anonymize', 'retain'])],
            'legal_basis_note' => ['required', 'string', 'max:2000'],
        ]);
        $data['version'] = (int) RetentionRule::query()->where('data_category', $data['data_category'])->max('version') + 1;

        $rule = new RetentionRule($data);
        $rule->forceFill(['status' => 'draft', 'drafted_by' => $staff->id])->save();

        return back()->with('status', 'Draft saved. Another person must record counsel\'s approval.');
    }

    /** One person proposes a reported batch; a different person approves and it runs (owner decision 2026-11-09). */
    public function retentionBatch(Request $request, string $batch, RetentionService $retention): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate(['action' => ['required', Rule::in(['propose', 'cancel', 'approve'])]]);

        if ($data['action'] === 'approve') {
            abort_unless(AccessContext::staffCan($staff, 'dpdp.retention_rules.record_approval'), 403);
            $summary = $retention->approveAndExecute($batch, $staff);

            return back()->with('status', "Disposal done: {$summary['done']} item(s). Skipped because of a hold: {$summary['skipped_hold']}. No longer due: {$summary['skipped_not_due']}.");
        }
        abort_unless(AccessContext::staffCan($staff, 'dpdp.retention_rules.draft'), 403);
        $data['action'] === 'propose' ? $retention->propose($batch, $staff) : $retention->cancel($batch, $staff);

        return back()->with('status', $data['action'] === 'propose' ? 'Proposed. A different person must approve it.' : 'Proposal cancelled.');
    }

    public function approveRetention(Request $request, RetentionRule $rule): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        abort_if($rule->drafted_by === $staff->id, 403, 'The person who drafted a rule cannot record its approval.');
        abort_unless($rule->status === 'draft', 422);
        $data = $request->validate(['approval_evidence' => ['required', 'string', 'max:2000']]);

        $rule->forceFill(['status' => 'approved', 'approved_by' => $staff->id, 'approved_at' => now(), 'approval_evidence' => $data['approval_evidence']])->save();
        $this->audit->record('privacy.retention_approved', 'retention_rule', $rule->id);

        return back()->with('status', 'Approval recorded.');
    }

    // Legal holds

    public function holds(): View
    {
        return view('staff.privacy.holds', ['holds' => LegalHold::query()->latest('placed_at')->limit(200)->get()]);
    }

    public function storeHold(Request $request): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate([
            'holdable_type' => ['required', Rule::in(['user', 'document', 'matter', 'tenant', 'odr_dispute'])],
            'holdable_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $hold = new LegalHold($data);
        $hold->forceFill(['placed_by' => $staff->id, 'placed_at' => now()])->save();
        $this->audit->record('privacy.hold_placed', $data['holdable_type'], $data['holdable_id']);

        return back()->with('status', 'Legal hold placed.');
    }

    public function releaseHold(Request $request, LegalHold $hold): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        abort_if($hold->released_at !== null, 422);
        $hold->forceFill(['released_at' => now(), 'released_by' => $staff->id])->save();
        $this->audit->record('privacy.hold_released', $hold->holdable_type, $hold->holdable_id);

        return back()->with('status', 'Legal hold released.');
    }

    // Breaches

    public function breaches(): View
    {
        return view('staff.privacy.breaches', ['incidents' => DataBreachIncident::query()->latest('detected_at')->get()]);
    }

    public function storeBreach(Request $request): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate([
            'detected_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'affected_principals' => ['nullable', 'integer', 'min:0'],
        ]);
        $incident = new DataBreachIncident($data);
        $incident->forceFill(['status' => 'open', 'owner_id' => $staff->id])->save();
        $this->audit->record('privacy.breach_opened', 'data_breach_incident', $incident->id, null, null, ['severity' => $data['severity']]);

        return back()->with('status', 'Incident recorded.');
    }

    public function updateBreach(Request $request, DataBreachIncident $incident): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'contained', 'reported', 'closed'])],
            'board_notified_at' => ['nullable', 'date'],
            'principals_notified_at' => ['nullable', 'date'],
        ]);
        $incident->forceFill(array_filter($data, fn ($v) => $v !== null))->save();
        $this->audit->record('privacy.breach_updated', 'data_breach_incident', $incident->id, null, null, ['status' => $data['status']]);

        return back()->with('status', 'Incident updated.');
    }

    // Disclaimers and consent purposes: a new version each time, since effective versions are immutable.

    public function policies(): View
    {
        return view('staff.privacy.policies', [
            'disclaimers' => Disclaimer::query()->orderBy('code')->orderByDesc('version')->get(),
            'purposes' => ConsentPurpose::query()->orderBy('code')->orderByDesc('version')->get(),
            'canDisclaimers' => true,
        ]);
    }

    public function storeDisclaimer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $data['version'] = (int) Disclaimer::query()->where('code', $data['code'])->max('version') + 1;
        Disclaimer::query()->create($data);

        return back()->with('status', 'New disclaimer version added.');
    }

    public function storePurpose(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $data['version'] = (int) ConsentPurpose::query()->where('code', $data['code'])->max('version') + 1;
        ConsentPurpose::query()->create($data);

        return back()->with('status', 'New consent purpose version added.');
    }

    // Audit log: keyset paging over a required date range (C28).

    public function audit(Request $request): View
    {
        return view('audit.index', $this->auditView($request, null) + ['title' => 'Platform audit log']);
    }

    /** @return array<string, mixed> */
    public static function auditView(Request $request, ?string $tenantId): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'event' => ['nullable', 'string', 'max:60']]);
        $from = $data['from'] ?? now()->subDays(30)->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        abort_if(now()->parse($from)->diffInDays(now()->parse($to)) > 366, 422, 'Choose a date range of at most 366 days.');

        $logs = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->whereBetween('a.created_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->when($tenantId !== null, fn ($q) => $q->where('a.tenant_id', $tenantId))
            ->when(! empty($data['event']), fn ($q) => $q->where('a.event', $data['event']))
            ->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->select(['a.id', 'a.created_at', 'a.event', 'a.auditable_type', 'a.auditable_id', 'a.channel', 'a.ip', 'a.new_values', 'u.name as actor'])
            ->cursorPaginate(50)->withQueryString();

        return ['logs' => $logs, 'from' => $from, 'to' => $to, 'event' => $data['event'] ?? ''];
    }
}
