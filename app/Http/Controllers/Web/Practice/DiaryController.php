<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\HearingRequest;
use App\Models\DiaryEntry;
use App\Models\Hearing;
use App\Models\Matter;
use App\Models\ScheduleConflict;
use App\Models\User;
use App\Services\HearingService;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DiaryController extends Controller
{
    /** The user's own hearings and entries for a week. */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $me = (string) MatterAccess::me($user);
        $from = Carbon::parse((string) $request->query('from', now()->toDateString()))->startOfDay();
        $to = $from->copy()->addDays(13)->endOfDay();

        $hearings = Hearing::query()->with(['matter', 'court'])
            ->where('tenant_id', AccessContext::currentTenantId())
            ->whereIn('id', DB::table('hearing_attendees')->where('membership_id', $me)->select('hearing_id'))
            ->whereBetween('hearing_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('hearing_date')->orderBy('scheduled_time')->get()
            ->filter(fn (Hearing $h) => $h->matter !== null && MatterAccess::canView($user, $h->matter));

        return view('practice.diary.index', [
            'from' => $from,
            'hearings' => $hearings,
            'entries' => DiaryEntry::query()->where('membership_id', $me)->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->get(),
            'conflicts' => $this->conflicts($user, $me),
            'canManageEntries' => AccessContext::tenantCan($user, 'diary.entries.manage_own'),
        ]);
    }

    /** D4.2: everyone's date, time and court. Titles only where the viewer can see the matter. */
    public function calendar(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $from = Carbon::parse((string) $request->query('from', now()->toDateString()))->startOfDay();
        $to = $from->copy()->addDays(13);

        $rows = Hearing::query()->with(['matter', 'court'])
            ->where('tenant_id', AccessContext::currentTenantId())
            ->whereBetween('hearing_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('hearing_date')->orderBy('scheduled_time')->get()
            ->map(fn (Hearing $h) => [
                'hearing' => $h,
                'title' => $h->matter !== null && MatterAccess::canView($user, $h->matter) ? "{$h->matter->matter_number}: {$h->matter->title}" : 'Engaged',
                'attendees' => DB::table('hearing_attendees as a')->join('tenant_memberships as m', 'm.id', '=', 'a.membership_id')
                    ->join('users as u', 'u.id', '=', 'm.user_id')->where('a.hearing_id', $h->id)->pluck('u.name')->implode(', '),
            ]);

        return view('practice.diary.calendar', ['from' => $from, 'rows' => $rows]);
    }

    public function storeHearing(HearingRequest $request, Matter $matter, HearingService $hearings): RedirectResponse
    {
        Gate::authorize('create', [Hearing::class, $matter]);
        /** @var User $user */
        $user = $request->user();

        $attendees = DB::table('matter_assignments')->where('matter_id', $matter->id)->whereNull('unassigned_at')->pluck('membership_id')->map(fn ($id) => (string) $id)->all();
        $hearing = $hearings->create($matter, $request->validated(), $attendees ?: [(string) MatterAccess::me($user)]);

        $conflicts = ScheduleConflict::query()->where('hearing_a_id', $hearing->id)->orWhere('hearing_b_id', $hearing->id)->count();

        return back()->with('status', $conflicts > 0 ? "Hearing added. Warning: {$conflicts} schedule conflict(s) found." : 'Hearing added.');
    }

    public function recordOutcome(Request $request, Hearing $hearing, HearingService $hearings): RedirectResponse
    {
        Gate::authorize('recordOutcome', $hearing);
        /** @var array{status: string, outcome_summary?: string|null, next_hearing_date?: string|null} $data */
        $data = $request->validate([
            'status' => ['required', Rule::in(['attended', 'adjourned', 'not_reached', 'disposed', 'cancelled'])],
            'outcome_summary' => ['nullable', 'string', 'max:5000'],
            'next_hearing_date' => ['nullable', 'date', 'after:'.$hearing->hearing_date->toDateString()],
        ]);
        $hearings->recordOutcome($hearing, $data);

        return back()->with('status', 'Outcome recorded.');
    }

    public function destroyHearing(Hearing $hearing): RedirectResponse
    {
        Gate::authorize('delete', $hearing);
        $hearing->delete();

        return back()->with('status', 'Hearing removed.');
    }

    public function storeEntry(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
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

        return back()->with('status', 'Diary updated.');
    }

    public function destroyEntry(Request $request, DiaryEntry $entry): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($entry->membership_id === MatterAccess::me($user), 404);
        $entry->delete();

        return back()->with('status', 'Diary updated.');
    }

    public function resolveConflict(Request $request, ScheduleConflict $conflict): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($conflict->tenant_id === AccessContext::currentTenantId(), 404);
        $own = $conflict->membership_id === MatterAccess::me($user) && AccessContext::tenantCan($user, 'conflicts.resolve_own');
        abort_unless($own || AccessContext::tenantCan($user, 'conflicts.resolve_all'), 404);
        abort_unless($conflict->status === 'open', 422);

        $data = $request->validate(['resolution_note' => ['nullable', 'string', 'max:1000']]);
        $conflict->forceFill(['status' => 'resolved', 'resolved_by' => $user->id, 'resolution_note' => $data['resolution_note'] ?? null])->save();

        return back()->with('status', 'Conflict resolved.');
    }

    /** @return Collection<int, ScheduleConflict> */
    private function conflicts(User $user, string $me)
    {
        return ScheduleConflict::query()->with(['hearingA.court', 'hearingB.court'])
            ->where('tenant_id', AccessContext::currentTenantId())->where('status', 'open')
            ->when(! AccessContext::tenantCan($user, 'conflicts.view_all'), fn ($q) => $q->where('membership_id', $me))
            ->orderByDesc('detected_at')->get();
    }
}
