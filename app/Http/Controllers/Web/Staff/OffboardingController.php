<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Services\OffboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Closing a practice. Staff see stages and metadata only, never the firm's content (DA-1). */
class OffboardingController extends Controller
{
    public function __construct(private OffboardingService $offboarding) {}

    public function index(): View
    {
        return view('staff.admin.offboarding.index', [
            'closures' => TenantOffboarding::query()->with('tenant:id,name,type,status')->latest('requested_at')->limit(200)->get(),
        ]);
    }

    public function create(Tenant $tenant): View
    {
        abort_if($tenant->type === 'platform', 404);

        return view('staff.admin.offboarding.create', ['tenant' => $tenant, 'recipients' => $this->offboarding->eligibleRecipients($tenant)]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_if($tenant->type === 'platform', 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'recipient_id' => ['required', 'uuid']]);
        $recipient = User::query()->find($data['recipient_id']);
        abort_if($recipient === null, 422, 'Choose who will receive the export.');
        $closure = $this->offboarding->request($this->user($request), $tenant, $data['reason'], $recipient);

        return redirect()->route('staff.admin.offboarding.show', $closure)->with('status', 'Closure requested. A different Super Admin must approve it.');
    }

    public function show(TenantOffboarding $offboarding): View
    {
        return view('staff.admin.offboarding.show', [
            'closure' => $offboarding->load(['tenant', 'recipient:id,name,email']),
            'names' => DB::table('users')->whereIn('id', array_filter([$offboarding->requested_by, $offboarding->approved_by, $offboarding->retention_started_by, $offboarding->cancelled_by]))->pluck('name', 'id'),
            'currentHolds' => $this->offboarding->activeHolds($offboarding->tenant_id),
        ]);
    }

    public function act(Request $request, TenantOffboarding $offboarding): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['action' => ['required', 'in:approve,export,waive,retention,cancel'], 'reason' => ['nullable', 'required_if:action,waive,cancel', 'string', 'max:2000']]);

        $action = (string) $data['action'];
        $reason = (string) ($data['reason'] ?? '');

        switch ($action) {
            case 'approve':
                $this->offboarding->approve($user, $offboarding);
                $message = 'Approved. The practice is suspended and its members can no longer open it.';
                break;
            case 'export':
                $this->offboarding->openExport($user, $offboarding);
                $message = 'The export is open. The recipient has been notified.';
                break;
            case 'waive':
                $this->offboarding->waiveExport($user, $offboarding, $reason);
                $message = 'Export waived. The reason is recorded.';
                break;
            case 'retention':
                $this->offboarding->startRetention($user, $offboarding);
                $message = 'The practice is closed. Its data is kept, and final disposal stays switched off.';
                break;
            case 'cancel':
                $this->offboarding->cancel($user, $offboarding, $reason);
                $message = 'Closure cancelled. The practice has its previous status back.';
                break;
            default:
                abort(422);
        }

        return back()->with('status', $message);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
