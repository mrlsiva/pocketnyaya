<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Practice\KnowledgeController as WebKnowledge;
use App\Http\Controllers\Web\Practice\SettingsController as WebSettings;
use App\Http\Controllers\Web\Staff\ComplianceController;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Integrations\Contracts\AiDrafting;
use App\Models\ConsultationRequest;
use App\Models\Document;
use App\Models\KnowledgeItem;
use App\Models\Matter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\ConsultationService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVault;
use App\Services\SubscriptionLimits;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Practice actions the web screens have, for the app: the consultation inbox, practice
 * settings, workload limits, the knowledge vault, the Drafting assistant and the audit log.
 * Each check matches its web counterpart.
 */
class PracticeAdminController extends Controller
{
    // Consultation inbox (the advocate's side)

    public function consultations(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $page = ConsultationRequest::query()->with('customer:id,name')->where('tenant_id', AccessContext::currentTenantId())
            ->when(! AccessContext::tenantCan($user, 'consultations.inbox.view_all'), fn ($q) => $q->where('advocate_membership_id', MatterAccess::me($user)))
            ->latest()->paginate(25);

        return response()->json($page->through(fn (ConsultationRequest $c) => $this->consultationSummary($c)));
    }

    public function consultation(Request $request, ConsultationRequest $consultation): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeConsultation($user, $consultation);
        $isAddressee = $consultation->advocate_membership_id === MatterAccess::me($user);

        return response()->json($this->consultationSummary($consultation) + [
            'description' => $consultation->description,
            'lawyer_private_notes' => $isAddressee ? $consultation->lawyer_private_notes : null,
            'messages' => $consultation->messages()->with('sender:id,name')->oldest()->get()
                ->map(fn ($m) => ['id' => $m->id, 'sender' => $m->sender?->name, 'body' => $m->body, 'created_at' => $m->created_at]),
            'can_respond' => $this->canRespond($user, $consultation),
            'can_convert' => $this->canConvert($user, $consultation) && in_array($consultation->status, ['accepted', 'completed'], true),
            'can_keep_notes' => $isAddressee && AccessContext::tenantCan($user, 'consultations.private_notes.manage_own'),
        ]);
    }

    public function respondConsultation(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeConsultation($user, $consultation);
        abort_unless($this->canRespond($user, $consultation), 403);
        $data = $request->validate(['status' => ['required', 'string'], 'scheduled_at' => ['nullable', 'date', 'after:now']]);
        $consultations->respond($consultation, $data['status'], $data['scheduled_at'] ?? null);

        return response()->json($this->consultationSummary($consultation->fresh() ?? $consultation));
    }

    public function messageConsultation(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeConsultation($user, $consultation);
        abort_unless($this->canRespond($user, $consultation), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $consultations->message($consultation, $user, $data['body']);

        return response()->json(['message' => 'Sent.'], 201);
    }

    public function consultationNotes(Request $request, ConsultationRequest $consultation): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($consultation->tenant_id === AccessContext::currentTenantId() && $consultation->advocate_membership_id === MatterAccess::me($user), 404);
        abort_unless(AccessContext::tenantCan($user, 'consultations.private_notes.manage_own'), 403);
        $data = $request->validate(['lawyer_private_notes' => ['nullable', 'string', 'max:10000']]);
        $consultation->forceFill(['lawyer_private_notes' => $data['lawyer_private_notes'] ?? null])->save();

        return response()->json(['message' => 'Private notes saved.']);
    }

    public function convertConsultation(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeConsultation($user, $consultation);
        abort_unless($this->canConvert($user, $consultation), 403);
        $matter = $consultations->convert($consultation, $user);

        return response()->json($matter->only(['id', 'matter_number', 'title', 'status', 'client_id']), 201);
    }

    // Practice settings

    public function settings(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $tenant = (string) AccessContext::currentTenantId();
        $me = (string) MatterAccess::me($user);
        $seesSettings = AccessContext::tenantCan($user, 'tenant.settings.view');

        return response()->json([
            'practice' => $seesSettings ? Tenant::query()->findOrFail($tenant)->only(['id', 'name', 'legal_name', 'type', 'gst_registration_type', 'gstin', 'state_code', 'address_line1', 'pincode']) : null,
            'branches' => $seesSettings ? DB::table('tenant_branches')->where('tenant_id', $tenant)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'state_code', 'city_id', 'is_head_office']) : [],
            'practice_groups' => $seesSettings ? DB::table('practice_groups')->where('tenant_id', $tenant)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'practice_area_id']) : [],
            'my_availability' => DB::table('membership_availability')->where(['tenant_id' => $tenant, 'membership_id' => $me])->orderByDesc('starts_on')->limit(20)->get(['id', 'starts_on', 'ends_on', 'availability', 'note']),
            'my_practice_area_ids' => DB::table('membership_practice_areas')->where('membership_id', $me)->pluck('practice_area_id')->map(fn ($id) => (int) $id)->values(),
            'subscription' => AccessContext::tenantCan($user, 'tenant.subscription.view')
                ? $this->subscription($tenant) : null,
            'can' => [
                'update' => AccessContext::tenantCan($user, 'tenant.settings.update'),
                'branches' => AccessContext::tenantCan($user, 'tenant.branches.manage'),
                'groups' => AccessContext::tenantCan($user, 'tenant.practice_groups.manage'),
                'availability' => AccessContext::tenantCan($user, 'tenant.availability.manage_own'),
            ],
        ]);
    }

    public function updateSettings(Request $request, AuditLogWriter $audit): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.settings.update'), 403);
        $tenant = Tenant::query()->findOrFail(AccessContext::currentTenantId());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'gst_registration_type' => ['required', Rule::in(['unregistered', 'regular', 'composition'])],
            'gstin' => ['nullable', 'required_unless:gst_registration_type,unregistered', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', Rule::unique('tenants', 'gstin')->ignore($tenant->id)],
            'state_code' => ['required', 'exists:states,code'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $tenant->update($data);
        $audit->record('tenant.settings_updated', 'tenant', $tenant->id, $tenant->id);

        return response()->json($tenant->only(['id', 'name', 'legal_name', 'gst_registration_type', 'gstin', 'state_code', 'address_line1', 'pincode']));
    }

    public function storeBranch(Request $request): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.branches.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'state_code' => ['required', 'exists:states,code'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'is_head_office' => ['sometimes', 'boolean'],
        ]);
        $tenant = (string) AccessContext::currentTenantId();
        abort_if(! empty($data['is_head_office']) && DB::table('tenant_branches')->where(['tenant_id' => $tenant, 'is_head_office' => true])->whereNull('deleted_at')->exists(), 422, 'This practice already has a head office.');
        $id = (string) Str::uuid7();
        DB::table('tenant_branches')->insert($data + ['id' => $id, 'tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id] + $data, 201);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.practice_groups.manage'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id']]);
        $id = (string) Str::uuid7();
        DB::table('practice_groups')->insert($data + ['id' => $id, 'tenant_id' => AccessContext::currentTenantId(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id] + $data, 201);
    }

    public function storeAvailability(Request $request): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.availability.manage_own'), 403);
        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'availability' => ['required', Rule::in(['leave', 'partial', 'unavailable'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $id = (string) Str::uuid7();
        DB::table('membership_availability')->insert($data + [
            'id' => $id, 'tenant_id' => AccessContext::currentTenantId(), 'membership_id' => MatterAccess::me($this->user($request)), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id] + $data, 201);
    }

    /** A member's own specialisations, used by the allocation matrix (PRD 2.4). Replaces the list. */
    public function practiceAreas(Request $request): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.availability.manage_own'), 403);
        $data = $request->validate(['practice_area_ids' => ['present', 'array', 'max:20'], 'practice_area_ids.*' => ['integer', 'exists:practice_areas,id']]);
        $areas = array_values(array_unique(array_map('intval', $data['practice_area_ids'])));
        WebSettings::syncAreas((string) MatterAccess::me($this->user($request)), $areas);

        return response()->json(['practice_area_ids' => $areas]);
    }

    public function capacity(Request $request, User $member): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.members.update'), 403);
        $tenant = AccessContext::currentTenantId();
        abort_unless(AccessContext::isActiveMember($member, $tenant), 404);
        $data = $request->validate(['max_active_matters' => ['present', 'nullable', 'integer', 'min:0', 'max:1000']]);
        DB::table('tenant_memberships')->where(['tenant_id' => $tenant, 'user_id' => $member->id])->update(['max_active_matters' => $data['max_active_matters'], 'updated_at' => now()]);

        return response()->json(['user_id' => $member->id, 'max_active_matters' => $data['max_active_matters']]);
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.audit.view'), 403);
        $view = ComplianceController::auditView($request, AccessContext::currentTenantId());

        return response()->json(['from' => $view['from'], 'to' => $view['to'], 'logs' => $view['logs']]);
    }

    // Knowledge vault

    public function storeKnowledge(Request $request, DocumentService $documents): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'knowledge.contribute'), 403);
        $data = $request->validate([
            'item_type' => ['required', Rule::in(WebKnowledge::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'source_matter_id' => ['nullable', 'uuid'],
            'file' => ['nullable', ...array_filter(UploadDocumentRequest::fileRules(), fn ($rule) => $rule !== 'required')],
        ]);
        unset($data['file']);
        $data['tags'] = array_values(array_filter(array_map('trim', $data['tags'] ?? [])));
        if (! empty($data['source_matter_id'])) {
            $matter = Matter::query()->find($data['source_matter_id']);
            abort_unless($matter !== null && MatterAccess::canView($user, $matter), 404);
        }
        $tenant = (string) AccessContext::currentTenantId();

        $item = DB::transaction(function () use ($request, $user, $data, $tenant, $documents) {
            $item = new KnowledgeItem($data);
            $file = $request->file('file');
            // The attachment is a purpose-bound general document (D8.10).
            $documentId = $file === null ? null : $documents->create($user, $file, ['title' => $data['title'], 'category' => 'other', 'confidentiality' => 'normal'], ['scope' => 'tenant', 'tenant_id' => $tenant])->id;
            $item->forceFill(['tenant_id' => $tenant, 'document_id' => $documentId, 'status' => 'draft', 'created_by' => $user->id])->save();

            return $item;
        });

        return response()->json($item->only(['id', 'item_type', 'title', 'status', 'document_id']), 201);
    }

    public function approveKnowledge(Request $request, KnowledgeItem $item): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($item->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($user, 'knowledge.approve'), 403);
        abort_unless($item->status === 'draft', 422, 'Only a draft can be approved.');
        $item->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save();

        return response()->json($item->only(['id', 'status', 'approved_at']));
    }

    public function archiveKnowledge(Request $request, KnowledgeItem $item): JsonResponse
    {
        abort_unless($item->tenant_id === AccessContext::currentTenantId(), 404);
        abort_unless(AccessContext::tenantCan($this->user($request), 'knowledge.archive'), 403);
        abort_unless($item->status === 'approved', 422, 'Only an approved item can be archived.');
        $item->forceFill(['status' => 'archived'])->save();

        return response()->json($item->only(['id', 'status']));
    }

    public function downloadKnowledge(Request $request, KnowledgeItem $item, DocumentVault $vault): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($item->tenant_id === AccessContext::currentTenantId() && $item->document_id !== null, 404);
        $allowed = $item->status === 'approved'
            ? AccessContext::tenantCan($user, 'knowledge.view')
            : $item->status === 'draft' && (AccessContext::tenantCan($user, 'knowledge.contribute') || AccessContext::tenantCan($user, 'knowledge.approve'));
        abort_unless($allowed, 404);
        $document = Document::query()->findOrFail($item->document_id);
        $version = $document->latestVersion();
        abort_if($version === null, 404);
        abort_if($version->isShredded(), 410);

        return $vault->download($document, $version, $user);
    }

    // Drafting assistant (503 until the AI service is switched on)

    public function aiDraft(Request $request, AiDrafting $ai): JsonResponse
    {
        $this->authorizeAi($request);
        $data = $request->validate(['document_type' => ['required', 'string', 'max:150'], 'facts' => ['required', 'string', 'max:8000']]);
        $text = $ai->draft($data['document_type'], ['Facts' => $data['facts']]);
        $this->auditAi($request, 'draft', mb_strlen($data['facts']));

        return response()->json(['text' => $text, 'notice' => 'AI output can be wrong and isn\'t legal advice. A qualified advocate must check it before use.']);
    }

    public function aiSummary(Request $request, AiDrafting $ai): JsonResponse
    {
        $this->authorizeAi($request);
        $data = $request->validate(['text' => ['required', 'string', 'max:30000']]);
        $text = $ai->summarise($data['text']);
        $this->auditAi($request, 'summary', mb_strlen($data['text']));

        return response()->json(['text' => $text, 'notice' => 'AI output can be wrong and isn\'t legal advice. A qualified advocate must check it before use.']);
    }

    private function authorizeAi(Request $request): void
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'knowledge.contribute') || AccessContext::tenantCan($user, 'knowledge.approve'), 403);
    }

    private function auditAi(Request $request, string $kind, int $length): void
    {
        app(AuditLogWriter::class)->record('ai.'.$kind.'_requested', 'user', $this->user($request)->id, AccessContext::currentTenantId(), null, ['input_length' => $length, 'channel' => 'api']);
    }

    /** @return array<string, mixed> no plan means no limits */
    private function subscription(string $tenant): array
    {
        $limits = app(SubscriptionLimits::class);
        $plan = $limits->currentPlan($tenant);

        return [
            'plan' => $plan === null ? null : ['code' => $plan->code, 'name' => $plan->name, 'status' => $plan->status, 'ends_at' => $plan->ends_at],
            'usage' => $limits->usage($tenant),
        ];
    }

    /** @return array<string, mixed> */
    private function consultationSummary(ConsultationRequest $c): array
    {
        return $c->only(['id', 'subject', 'status', 'mode', 'preferred_slots', 'scheduled_at', 'advocate_membership_id', 'created_at']) + ['customer' => $c->customer?->name];
    }

    private function authorizeConsultation(User $user, ConsultationRequest $consultation): void
    {
        $visible = $consultation->tenant_id === AccessContext::currentTenantId() && (
            AccessContext::tenantCan($user, 'consultations.inbox.view_all')
            || (AccessContext::tenantCan($user, 'consultations.inbox.view_own') && $consultation->advocate_membership_id === MatterAccess::me($user))
        );
        abort_unless($visible, 404);
    }

    private function canRespond(User $user, ConsultationRequest $consultation): bool
    {
        return AccessContext::tenantCan($user, 'consultations.respond_any')
            || (AccessContext::tenantCan($user, 'consultations.respond_own') && $consultation->advocate_membership_id === MatterAccess::me($user));
    }

    private function canConvert(User $user, ConsultationRequest $consultation): bool
    {
        return AccessContext::tenantCan($user, 'consultations.convert')
            && ($consultation->advocate_membership_id === MatterAccess::me($user) || AccessContext::tenantCan($user, 'consultations.inbox.view_all'));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
