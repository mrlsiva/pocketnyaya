<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\MatterRequest;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\MatterTask;
use App\Models\User;
use App\Policies\MatterPolicy;
use App\Services\MatterService;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MatterController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $status = (string) $request->query('status');

        $matters = MatterAccess::visibleMatters(Matter::query(), $user)
            ->with('client')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('opened_at')->paginate(25)->withQueryString();

        return view('practice.matters.index', ['matters' => $matters, 'status' => $status]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Matter::class);
        /** @var User $user */
        $user = $request->user();

        return view('practice.matters.form', $this->formData($user) + ['matter' => new Matter(['client_id' => $request->query('client'), 'priority' => 'normal'])]);
    }

    public function store(MatterRequest $request, MatterService $matters): RedirectResponse
    {
        Gate::authorize('create', Matter::class);
        /** @var User $user */
        $user = $request->user();

        $client = Client::query()->find($request->validated('client_id'));
        abort_unless($client !== null && MatterAccess::canViewClient($user, $client), 422, 'That client is not available.');

        $matter = $matters->create($user, $request->validated());

        return redirect()->route('practice.matters.show', $matter)->with('status', "Matter {$matter->matter_number} created.");
    }

    public function show(Request $request, Matter $matter, MatterPolicy $policy): View
    {
        Gate::authorize('view', $matter);
        /** @var User $user */
        $user = $request->user();

        $members = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $matter->tenant_id)->where('m.status', 'active')->whereNull('m.deleted_at')
            ->orderBy('u.name')->get(['m.id', 'u.name']);

        return view('practice.matters.show', [
            'matter' => $matter->load(['client', 'court', 'parties']),
            'assignments' => DB::table('matter_assignments as a')
                ->join('tenant_memberships as m', 'm.id', '=', 'a.membership_id')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('a.matter_id', $matter->id)->whereNull('a.unassigned_at')->get(['a.id', 'a.role_on_matter', 'u.name']),
            'tasks' => $matter->tasks()->with('assignee.user')->orderBy('due_at')->get(),
            'notes' => $matter->notes()->with('author')->latest()->get()->filter(fn (MatterNote $n) => $policy->canViewNote($user, $n)),
            'hearings' => AccessContext::tenantCan($user, 'hearings.view') ? $matter->hearings()->with('court')->get() : collect(),
            'documents' => Document::query()->visibleTo($user)->where('matter_id', $matter->id)->latest()->get(),
            'members' => $members,
            'courts' => DB::table('courts')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Request $request, Matter $matter): View
    {
        Gate::authorize('update', $matter);
        /** @var User $user */
        $user = $request->user();

        return view('practice.matters.form', $this->formData($user) + ['matter' => $matter]);
    }

    public function update(MatterRequest $request, Matter $matter): RedirectResponse
    {
        Gate::authorize('update', $matter);
        $matter->update($request->validated());

        return redirect()->route('practice.matters.show', $matter)->with('status', 'Matter updated.');
    }

    public function destroy(Matter $matter): RedirectResponse
    {
        Gate::authorize('delete', $matter);
        $matter->delete();

        return redirect()->route('practice.matters.index')->with('status', 'Matter removed.');
    }

    public function close(Matter $matter, MatterService $matters): RedirectResponse
    {
        Gate::authorize('close', $matter);
        $matters->close($matter);

        return back()->with('status', 'Matter closed.');
    }

    public function reopen(Matter $matter, MatterService $matters): RedirectResponse
    {
        Gate::authorize('reopen', $matter);
        $matters->reopen($matter);

        return back()->with('status', 'Matter reopened.');
    }

    public function archive(Matter $matter): RedirectResponse
    {
        Gate::authorize('archive', $matter);
        $matter->forceFill(['status' => 'archived'])->save();

        return back()->with('status', 'Matter archived.');
    }

    public function assign(Request $request, Matter $matter, MatterService $matters): RedirectResponse
    {
        Gate::authorize('allocate', $matter);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role_on_matter' => ['required', Rule::in(['lead', 'associate', 'junior', 'clerk', 'finance_viewer'])],
        ]);
        $matters->assign($user, $matter, $data['membership_id'], $data['role_on_matter']);

        return back()->with('status', 'Team updated.');
    }

    public function unassign(Matter $matter, string $assignment): RedirectResponse
    {
        Gate::authorize('allocate', $matter);
        DB::table('matter_assignments')->where(['id' => $assignment, 'matter_id' => $matter->id])->update(['unassigned_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Team updated.');
    }

    public function restriction(Request $request, Matter $matter, MatterService $matters): RedirectResponse
    {
        Gate::authorize('setBillingRestriction', $matter);
        $matters->setBillingRestriction($matter, $request->boolean('restricted'));

        return back()->with('status', 'Billing visibility updated.');
    }

    public function storeParty(Request $request, Matter $matter): RedirectResponse
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

        return back()->with('status', 'Party added.');
    }

    public function storeTask(Request $request, Matter $matter): RedirectResponse
    {
        Gate::authorize('manageTasks', $matter);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'assigned_to_membership_id' => ['nullable', 'uuid', Rule::exists('tenant_memberships', 'id')->where('tenant_id', $matter->tenant_id)->where('status', 'active')],
            'due_at' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        $task = new MatterTask($data);
        $task->forceFill(['tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id, 'assigned_by' => $user->id])->save();

        return back()->with('status', 'Task added.');
    }

    public function updateTaskStatus(Request $request, MatterTask $task): RedirectResponse
    {
        Gate::authorize('updateTaskStatus', [Matter::class, $task]);
        $data = $request->validate(['status' => ['required', Rule::in(['todo', 'in_progress', 'done'])]]);
        $task->forceFill(['status' => $data['status'], 'completed_at' => $data['status'] === 'done' ? now() : null])->save();

        return back()->with('status', 'Task updated.');
    }

    public function storeNote(Request $request, Matter $matter): RedirectResponse
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

        return back()->with('status', 'Note added.');
    }

    public function destroyNote(MatterNote $note): RedirectResponse
    {
        Gate::authorize('changeNote', [Matter::class, $note]);
        $note->delete();

        return back()->with('status', 'Note removed.');
    }

    /** @return array<string, mixed> */
    private function formData(User $user): array
    {
        return [
            'clients' => MatterAccess::visibleClients(Client::query(), $user)->orderBy('name')->get(['id', 'name']),
            'areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'courts' => DB::table('courts')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
