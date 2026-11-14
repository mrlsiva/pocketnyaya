<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DiaryEntry;
use App\Models\Hearing;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\MatterTask;
use App\Models\ScheduleConflict;
use App\Models\User;
use App\Services\AllocationCandidate;
use App\Services\AllocationService;
use App\Services\MatterService;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The matter and diary actions the web screens have, for the app. Each one uses the same
 * policy check, validation and service as its web counterpart (Web\Practice\MatterController,
 * DiaryController and ClientController).
 */
class MatterWorkController extends Controller
{
    public function destroyClient(Client $client): JsonResponse
    {
        Gate::authorize('delete', $client);
        abort_if(LegalHold::coversClient($client), 422, 'A legal hold covers this client, so it cannot be removed.');
        $client->delete();

        return response()->json(['message' => 'Client removed.']);
    }

    public function destroyMatter(Matter $matter): JsonResponse
    {
        Gate::authorize('delete', $matter);
        abort_if(LegalHold::coversMatter($matter), 422, 'A legal hold covers this matter, so it cannot be removed.');
        $matter->delete();

        return response()->json(['message' => 'Matter removed.']);
    }

    public function close(Matter $matter, MatterService $matters): JsonResponse
    {
        Gate::authorize('close', $matter);
        $matters->close($matter);

        return $this->matter($matter);
    }

    public function reopen(Matter $matter, MatterService $matters): JsonResponse
    {
        Gate::authorize('reopen', $matter);
        $matters->reopen($matter);

        return $this->matter($matter);
    }

    public function archive(Matter $matter): JsonResponse
    {
        Gate::authorize('archive', $matter);
        $matter->forceFill(['status' => 'archived'])->save();

        return $this->matter($matter);
    }

    /** PRD 2.4: availability, practice area, capacity and load for each member, best first. */
    public function allocation(Matter $matter): JsonResponse
    {
        Gate::authorize('allocate', $matter);

        return response()->json(['data' => app(AllocationService::class)->candidates($matter)->map(fn (AllocationCandidate $c) => [
            'membership_id' => $c->id, 'name' => $c->name, 'roles' => $c->roles, 'practice_areas' => $c->areas,
            'matches_area' => $c->matches_area, 'availability' => $c->availability, 'unavailable_until' => $c->unavailable_until,
            'active_matters' => $c->active_matters, 'max_active_matters' => $c->max_active_matters, 'at_capacity' => $c->at_capacity,
        ])->values()]);
    }

    public function assignments(Matter $matter): JsonResponse
    {
        Gate::authorize('view', $matter);

        return response()->json(['data' => DB::table('matter_assignments')->join('tenant_memberships', 'tenant_memberships.id', '=', 'matter_assignments.membership_id')
            ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->where('matter_assignments.matter_id', $matter->id)->whereNull('matter_assignments.unassigned_at')
            ->get(['matter_assignments.id', 'matter_assignments.membership_id', 'matter_assignments.role_on_matter', 'users.name'])]);
    }

    public function unassign(Matter $matter, string $assignment): JsonResponse
    {
        Gate::authorize('allocate', $matter);
        $changed = DB::table('matter_assignments')->where(['id' => $assignment, 'matter_id' => $matter->id])->whereNull('unassigned_at')
            ->update(['unassigned_at' => now(), 'updated_at' => now()]);
        abort_if($changed === 0, 404);

        return response()->json(['message' => 'Team updated.']);
    }

    public function restriction(Request $request, Matter $matter, MatterService $matters): JsonResponse
    {
        Gate::authorize('setBillingRestriction', $matter);
        $data = $request->validate(['restricted' => ['required', 'boolean']]);
        $matters->setBillingRestriction($matter, (bool) $data['restricted']);

        return response()->json(['message' => 'Billing visibility updated.']);
    }

    public function parties(Matter $matter): JsonResponse
    {
        Gate::authorize('view', $matter);

        return response()->json(['data' => $matter->parties()->get(['id', 'party_role', 'name', 'is_our_client', 'counsel_name'])]);
    }

    public function storeParty(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('manageParties', $matter);
        $data = $request->validate([
            'party_role' => ['required', Rule::in(['petitioner', 'respondent', 'appellant', 'plaintiff', 'defendant', 'complainant', 'accused', 'other'])],
            'name' => ['required', 'string', 'max:200'],
            'is_our_client' => ['sometimes', 'boolean'],
            'counsel_name' => ['nullable', 'string', 'max:150'],
            'contact' => ['nullable', 'string', 'max:500'],
        ]);
        $party = $matter->parties()->make($data);
        $party->forceFill(['tenant_id' => $matter->tenant_id])->save();

        return response()->json($party->only(['id', 'party_role', 'name', 'is_our_client', 'counsel_name']), 201);
    }

    public function storeTask(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('manageTasks', $matter);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'assigned_to_membership_id' => ['nullable', 'uuid', Rule::exists('tenant_memberships', 'id')->where('tenant_id', $matter->tenant_id)->where('status', 'active')],
            'due_at' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);
        $task = new MatterTask($data);
        $task->forceFill(['tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id, 'assigned_by' => $this->user($request)->id])->save();
        if ($task->assigned_to_membership_id !== null) {
            Realtime::toUser((string) DB::table('tenant_memberships')->where('id', $task->assigned_to_membership_id)->value('user_id'), 'task.assigned', ['task_id' => $task->id, 'matter_id' => $matter->id]);
        }

        return response()->json($task->only(['id', 'matter_id', 'title', 'assigned_to_membership_id', 'due_at', 'priority', 'status']), 201);
    }

    public function destroyNote(MatterNote $note): JsonResponse
    {
        Gate::authorize('changeNote', [Matter::class, $note]);
        $note->delete();

        return response()->json(['message' => 'Note removed.']);
    }

    public function destroyHearing(Hearing $hearing): JsonResponse
    {
        Gate::authorize('delete', $hearing);
        $hearing->delete();

        return response()->json(['message' => 'Hearing removed.']);
    }

    /** D4.2: everyone's date, time and court. Titles only where the viewer can see the matter. */
    public function calendar(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['from' => ['nullable', 'date']]);
        $from = Carbon::parse($data['from'] ?? now()->toDateString())->startOfDay();
        $to = $from->copy()->addDays(13);

        $rows = Hearing::query()->with('matter')->where('tenant_id', AccessContext::currentTenantId())
            ->whereBetween('hearing_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('hearing_date')->orderBy('scheduled_time')->get()
            ->map(function (Hearing $h) use ($user) {
                $visible = $h->matter !== null && MatterAccess::canView($user, $h->matter);

                return [
                    'id' => $h->id, 'hearing_date' => $h->hearing_date->toDateString(), 'scheduled_time' => $h->scheduled_time,
                    'court_id' => $h->court_id, 'court_hall' => $h->court_hall, 'status' => $h->status,
                    'matter_id' => $visible ? $h->matter_id : null,
                    'title' => $visible ? "{$h->matter->matter_number}: {$h->matter->title}" : 'Engaged',
                    'attendees' => DB::table('hearing_attendees')->join('tenant_memberships', 'tenant_memberships.id', '=', 'hearing_attendees.membership_id')
                        ->join('users', 'users.id', '=', 'tenant_memberships.user_id')->where('hearing_attendees.hearing_id', $h->id)->pluck('users.name')->values(),
                ];
            })->values();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'data' => $rows]);
    }

    public function diaryEntries(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date']]);
        $from = Carbon::parse($data['from'] ?? now()->toDateString())->startOfDay();

        return response()->json(['data' => DiaryEntry::query()->where('membership_id', MatterAccess::me($this->user($request)))
            ->whereBetween('starts_at', [$from, $from->copy()->addDays(13)->endOfDay()])->orderBy('starts_at')
            ->get(['id', 'entry_type', 'title', 'starts_at', 'ends_at', 'location', 'matter_id'])]);
    }

    public function storeDiaryEntry(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::tenantCan($user, 'diary.entries.manage_own'), 403);
        $data = $request->validate([
            'entry_type' => ['required', Rule::in(['meeting', 'reminder', 'filing_deadline', 'personal'])],
            'title' => ['required', 'string', 'max:200'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:200'],
            'matter_id' => ['nullable', 'uuid'],
        ]);
        if (! empty($data['matter_id'])) {
            $matter = Matter::query()->find($data['matter_id']);
            abort_unless($matter !== null && MatterAccess::canView($user, $matter), 404);
        }
        $entry = new DiaryEntry($data);
        $entry->forceFill(['tenant_id' => AccessContext::currentTenantId(), 'membership_id' => MatterAccess::me($user)])->save();

        return response()->json($entry->only(['id', 'entry_type', 'title', 'starts_at', 'ends_at', 'location', 'matter_id']), 201);
    }

    public function destroyDiaryEntry(Request $request, DiaryEntry $entry): JsonResponse
    {
        abort_unless($entry->membership_id === MatterAccess::me($this->user($request)), 404);
        $entry->delete();

        return response()->json(['message' => 'Diary updated.']);
    }

    public function resolveConflict(Request $request, ScheduleConflict $conflict): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($conflict->tenant_id === AccessContext::currentTenantId(), 404);
        $own = $conflict->membership_id === MatterAccess::me($user) && AccessContext::tenantCan($user, 'conflicts.resolve_own');
        abort_unless($own || AccessContext::tenantCan($user, 'conflicts.resolve_all'), 404);
        abort_unless($conflict->status === 'open', 422, 'This conflict is already resolved.');
        $data = $request->validate(['resolution_note' => ['nullable', 'string', 'max:1000']]);
        $conflict->forceFill(['status' => 'resolved', 'resolved_by' => $user->id, 'resolution_note' => $data['resolution_note'] ?? null])->save();

        return response()->json(['message' => 'Conflict resolved.']);
    }

    private function matter(Matter $matter): JsonResponse
    {
        $fresh = $matter->fresh() ?? $matter;

        return response()->json($fresh->only(['id', 'matter_number', 'title', 'matter_type', 'status', 'priority', 'client_id', 'opened_at']));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
