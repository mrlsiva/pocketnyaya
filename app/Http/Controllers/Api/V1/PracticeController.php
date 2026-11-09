<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\ClientRequest;
use App\Http\Requests\Practice\HearingRequest;
use App\Http\Requests\Practice\MatterRequest;
use App\Models\Client;
use App\Models\Hearing;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\MatterTask;
use App\Models\User;
use App\Policies\MatterPolicy;
use App\Services\HearingService;
use App\Services\MatterService;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Clients, matters and the court diary for mobile apps. Tenant comes from X-Tenant-ID. */
class PracticeController extends Controller
{
    // Clients

    public function clients(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = MatterAccess::visibleClients(Client::query(), $user)->orderBy('name')->paginate(25);

        return response()->json($page->through(fn (Client $c) => $this->clientSummary($c)));
    }

    public function storeClient(ClientRequest $request): JsonResponse
    {
        Gate::authorize('create', Client::class);
        $client = new Client($request->validated());
        $client->forceFill(['tenant_id' => AccessContext::currentTenantId()])->save();

        return response()->json($this->clientDetail($client), 201);
    }

    public function showClient(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return response()->json($this->clientDetail($client));
    }

    public function updateClient(ClientRequest $request, Client $client): JsonResponse
    {
        Gate::authorize('update', $client);
        $client->update($request->validated());

        return response()->json($this->clientDetail($client));
    }

    // Matters

    public function matters(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = MatterAccess::visibleMatters(Matter::query(), $user)->orderByDesc('opened_at')->paginate(25);

        return response()->json($page->through(fn (Matter $m) => $this->matterSummary($m)));
    }

    public function storeMatter(MatterRequest $request, MatterService $matters): JsonResponse
    {
        Gate::authorize('create', Matter::class);
        /** @var User $user */
        $user = $request->user();
        $client = Client::query()->find($request->validated('client_id'));
        abort_unless($client !== null && MatterAccess::canViewClient($user, $client), 422, 'That client is not available.');

        return response()->json($this->matterSummary($matters->create($user, $request->validated())), 201);
    }

    public function showMatter(Request $request, Matter $matter, MatterPolicy $policy): JsonResponse
    {
        Gate::authorize('view', $matter);
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->matterSummary($matter) + [
            'description' => $matter->description,
            'court_id' => $matter->court_id,
            'case_number' => $matter->case_number,
            'cnr_number' => $matter->cnr_number,
            'parties' => $matter->parties()->get(['id', 'party_role', 'name', 'is_our_client', 'counsel_name']),
            'notes' => $matter->notes()->latest()->get()->filter(fn (MatterNote $n) => $policy->canViewNote($user, $n))
                ->map(fn (MatterNote $n) => ['id' => $n->id, 'body' => $n->body, 'visibility' => $n->visibility, 'mine' => $n->author_user_id === $user->id, 'created_at' => $n->created_at])->values(),
        ]);
    }

    public function updateMatter(MatterRequest $request, Matter $matter): JsonResponse
    {
        Gate::authorize('update', $matter);
        $matter->update($request->validated());

        return response()->json($this->matterSummary($matter));
    }

    public function assign(Request $request, Matter $matter, MatterService $matters): JsonResponse
    {
        Gate::authorize('allocate', $matter);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role_on_matter' => ['required', Rule::in(['lead', 'associate', 'junior', 'clerk', 'finance_viewer'])],
        ]);
        $matters->assign($user, $matter, $data['membership_id'], $data['role_on_matter']);

        return response()->json($this->matterSummary($matter->fresh() ?? $matter), 201);
    }

    public function storeNote(Request $request, Matter $matter): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'visibility' => ['required', Rule::in(['team', 'private', 'partners'])],
        ]);
        Gate::authorize('createNote', [$matter, $data['visibility']]);
        /** @var User $user */
        $user = $request->user();

        $note = new MatterNote($data);
        $note->forceFill(['tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id, 'author_user_id' => $user->id])->save();

        return response()->json(['id' => $note->id, 'visibility' => $note->visibility], 201);
    }

    public function tasks(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $me = MatterAccess::me($user);

        $tasks = MatterTask::query()->where('tenant_id', AccessContext::currentTenantId())
            ->where('assigned_to_membership_id', $me)->whereNotIn('status', ['done', 'cancelled'])->orderBy('due_at')->paginate(25);

        return response()->json($tasks->through(fn (MatterTask $t) => $t->only(['id', 'matter_id', 'title', 'due_at', 'priority', 'status'])));
    }

    public function updateTask(Request $request, MatterTask $task): JsonResponse
    {
        Gate::authorize('updateTaskStatus', [Matter::class, $task]);
        $data = $request->validate(['status' => ['required', Rule::in(['todo', 'in_progress', 'done'])]]);
        $task->forceFill(['status' => $data['status'], 'completed_at' => $data['status'] === 'done' ? now() : null])->save();

        return response()->json($task->only(['id', 'status', 'completed_at']));
    }

    // Court diary

    public function diary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $me = (string) MatterAccess::me($user);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? now()->addDays(13)->toDateString();

        $hearings = Hearing::query()->with('matter')->where('tenant_id', AccessContext::currentTenantId())
            ->whereIn('id', DB::table('hearing_attendees')->where('membership_id', $me)->select('hearing_id'))
            ->whereBetween('hearing_date', [$from, $to])->orderBy('hearing_date')->get()
            ->filter(fn (Hearing $h) => $h->matter !== null && MatterAccess::canView($user, $h->matter))
            ->map(fn (Hearing $h) => $this->hearingPayload($h))->values();

        return response()->json(['data' => $hearings]);
    }

    public function storeHearing(HearingRequest $request, Matter $matter, HearingService $hearings): JsonResponse
    {
        Gate::authorize('create', [Hearing::class, $matter]);
        /** @var User $user */
        $user = $request->user();
        $attendees = DB::table('matter_assignments')->where('matter_id', $matter->id)->whereNull('unassigned_at')->pluck('membership_id')->map(fn ($id) => (string) $id)->all();

        return response()->json($this->hearingPayload($hearings->create($matter, $request->validated(), $attendees ?: [(string) MatterAccess::me($user)])), 201);
    }

    public function recordOutcome(Request $request, Hearing $hearing, HearingService $hearings): JsonResponse
    {
        Gate::authorize('recordOutcome', $hearing);
        /** @var array{status: string, outcome_summary?: string|null, next_hearing_date?: string|null} $data */
        $data = $request->validate([
            'status' => ['required', Rule::in(['attended', 'adjourned', 'not_reached', 'disposed', 'cancelled'])],
            'outcome_summary' => ['nullable', 'string', 'max:5000'],
            'next_hearing_date' => ['nullable', 'date', 'after:'.$hearing->hearing_date->toDateString()],
        ]);

        return response()->json($this->hearingPayload($hearings->recordOutcome($hearing, $data)));
    }

    public function conflicts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $query = DB::table('schedule_conflicts')->where('tenant_id', AccessContext::currentTenantId())->where('status', 'open');
        if (! AccessContext::tenantCan($user, 'conflicts.view_all')) {
            $query->where('membership_id', MatterAccess::me($user));
        }

        return response()->json(['data' => $query->get(['id', 'membership_id', 'hearing_a_id', 'hearing_b_id', 'conflict_type', 'detected_at'])]);
    }

    /** @return array<string, mixed> */
    private function clientSummary(Client $client): array
    {
        return $client->only(['id', 'name', 'client_type', 'email', 'state_code']);
    }

    /** @return array<string, mixed> */
    private function clientDetail(Client $client): array
    {
        return $this->clientSummary($client) + ['phone' => $client->phone, 'address' => $client->address, 'gstin' => $client->gstin];
    }

    /** @return array<string, mixed> */
    private function matterSummary(Matter $matter): array
    {
        return $matter->only(['id', 'matter_number', 'title', 'matter_type', 'status', 'priority', 'client_id', 'opened_at']);
    }

    /** @return array<string, mixed> */
    private function hearingPayload(Hearing $hearing): array
    {
        return $hearing->only(['id', 'matter_id', 'court_id', 'court_hall', 'hearing_date', 'scheduled_time', 'purpose', 'status', 'outcome_summary', 'next_hearing_date', 'next_hearing_id']);
    }
}
