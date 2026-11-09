<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\ForumController;
use App\Models\AdvocateProfile;
use App\Models\ConsentPurpose;
use App\Models\DataPrincipalRequest;
use App\Models\ForumAnswer;
use App\Models\ForumQuestion;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\ConsentLedger;
use App\Services\ForumService;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Forum, knowledge, privacy, and device registration for mobile apps. */
class CommunityController extends Controller
{
    public function questions(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q'));
        $page = ForumQuestion::query()->where('status', 'published')
            ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('published_at')->paginate(20);

        return response()->json($page->through(fn (ForumQuestion $q) => $this->question($q)));
    }

    public function question_(ForumQuestion $question): JsonResponse
    {
        abort_unless($question->status === 'published', 404);

        return response()->json($this->question($question) + [
            'body' => $question->body,
            'answers' => $question->answers()->with('advocate.user:id,name')->where('status', 'published')->orderBy('published_at')->get()
                ->map(fn (ForumAnswer $a) => ['id' => $a->id, 'body' => $a->body, 'advocate' => $a->advocate?->user?->name, 'published_at' => $a->published_at]),
        ]);
    }

    public function ask(Request $request, ForumService $forum): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:10', 'max:200'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id'],
        ]);
        $question = $forum->ask($this->user($request), $data);

        return response()->json($this->question($question) + ['status' => $question->status], 201);
    }

    public function answer(Request $request, ForumQuestion $question, ForumService $forum): JsonResponse
    {
        abort_unless($question->status === 'published', 404);
        /** @var AdvocateProfile|null $profile */
        $profile = $this->user($request)->advocateProfile;
        abort_unless($profile !== null && $profile->verification_status === 'verified', 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:20', 'max:10000']]);
        $answer = $forum->answer($question, $profile, $data['body']);

        return response()->json(['id' => $answer->id, 'status' => $answer->status], 201);
    }

    public function report(Request $request, ForumService $forum): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['forum_question', 'forum_answer', 'forum_comment'])],
            'id' => ['required', 'uuid'],
            'reason' => ['required', Rule::in(ForumController::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $forum->report($this->user($request), $data['type'], $data['id'], $data['reason'], $data['details'] ?? null);

        return response()->json(['message' => 'Thank you. A moderator will review this.'], 201);
    }

    public function knowledge(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $contributor = AccessContext::tenantCan($user, 'knowledge.contribute') || AccessContext::tenantCan($user, 'knowledge.approve');
        $page = KnowledgeItem::query()->where('tenant_id', AccessContext::currentTenantId())->whereIn('status', $contributor ? ['approved', 'draft'] : ['approved'])->latest()->paginate(25);

        return response()->json($page->through(fn (KnowledgeItem $k) => $k->only(['id', 'item_type', 'title', 'summary', 'tags', 'status', 'document_id'])));
    }

    public function consents(Request $request): JsonResponse
    {
        $states = DB::table('user_consents')->where('user_id', $this->user($request)->id)->pluck('status', 'consent_purpose_id');

        return response()->json(['data' => ConsentPurpose::query()->current()->get()->map(fn (ConsentPurpose $p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'is_mandatory' => $p->is_mandatory, 'status' => $states[$p->id] ?? null,
        ])]);
    }

    public function updateConsent(Request $request, ConsentPurpose $purpose, ConsentLedger $ledger): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['grant', 'withdraw'])]]);
        abort_unless($purpose->is_active, 404);
        $user = $this->user($request);
        $data['action'] === 'grant' ? $ledger->grant($user, $purpose, 'mobile') : $ledger->withdraw($user, $purpose, 'mobile');

        return response()->json(['code' => $purpose->code, 'status' => $data['action'] === 'grant' ? 'granted' : 'withdrawn']);
    }

    public function privacyRequests(Request $request): JsonResponse
    {
        return response()->json(['data' => DataPrincipalRequest::query()->where('user_id', $this->user($request)->id)->latest()->get(['id', 'request_type', 'status', 'due_at', 'completed_at', 'retained_items', 'created_at'])]);
    }

    public function storePrivacyRequest(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['request_type' => ['required', Rule::in(['erasure', 'access', 'correction', 'grievance', 'nomination'])], 'details' => ['nullable', 'string', 'max:2000']]);
        $exists = DataPrincipalRequest::query()->where(['user_id' => $user->id, 'request_type' => $data['request_type']])->whereIn('status', ['submitted', 'identity_verification', 'in_review', 'approved'])->exists();
        abort_if($exists, 422, 'You already have an open request of this type.');

        $privacyRequest = new DataPrincipalRequest($data);
        $privacyRequest->forceFill(['user_id' => $user->id, 'status' => 'submitted', 'due_at' => now()->addDays((int) config('pocketnyaya.privacy_request_days'))])->save();

        return response()->json($privacyRequest->only(['id', 'request_type', 'status', 'due_at']), 201);
    }

    /** Stores the push token for later use. Push sending itself is a later integration. */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['platform' => ['required', Rule::in(['android', 'ios', 'web'])], 'push_token' => ['required', 'string', 'max:4096'], 'app_version' => ['nullable', 'string', 'max:30']]);
        $user = $this->user($request);
        $hash = hash('sha256', $data['push_token']);

        DB::table('user_devices')->where('push_token_hash', $hash)->where('user_id', '<>', $user->id)->delete();
        $existing = DB::table('user_devices')->where(['user_id' => $user->id, 'push_token_hash' => $hash])->value('id');
        if ($existing !== null) {
            DB::table('user_devices')->where('id', $existing)->update(['platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null, 'last_seen_at' => now(), 'updated_at' => now()]);

            return response()->json(['id' => $existing]);
        }

        $id = (string) Str::uuid7();
        DB::table('user_devices')->insert($data + ['id' => $id, 'user_id' => $user->id, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id], 201);
    }

    public function removeDevice(Request $request, string $device): JsonResponse
    {
        $deleted = DB::table('user_devices')->where(['id' => $device, 'user_id' => $this->user($request)->id])->delete();
        abort_if($deleted === 0, 404);

        return response()->json(null, 204);
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->user($request)->notifications()->latest()->limit(50)->get()
            ->map(fn ($n) => ['id' => $n->id, 'type' => class_basename($n->type), 'data' => $n->data, 'read_at' => $n->read_at, 'created_at' => $n->getAttribute('created_at')])]);
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $row = $this->user($request)->notifications()->whereKey($notification)->first();
        abort_if($row === null, 404);
        $row->markAsRead();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function question(ForumQuestion $question): array
    {
        return $question->only(['id', 'public_alias', 'title', 'practice_area_id', 'published_at']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
