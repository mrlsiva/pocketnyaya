<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmergencyRequest;
use App\Models\EmergencyResponder;
use App\Models\User;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** DA-24: signed-in users only. DA-9: the Compliance Officer triages. */
class EmergencyController extends Controller
{
    private const SLA_MINUTES = ['p1' => 30, 'p2' => 120, 'p3' => 480];

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('emergency.create', [
            'cities' => DB::table('cities')->orderBy('name')->get(['id', 'name']),
            'languages' => DB::table('languages')->orderBy('name')->get(['id', 'name']),
            'mine' => EmergencyRequest::query()->with('city')->where('customer_user_id', $user->id)->latest()->limit(10)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'category' => ['required', Rule::in(['domestic_violence', 'harassment', 'protection_order', 'other'])],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'preferred_language_id' => ['nullable', 'integer', 'exists:languages,id'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        // Urgent categories get the shortest response target.
        $priority = in_array($data['category'], ['domestic_violence', 'protection_order'], true) ? 'p1' : 'p2';

        $emergency = new EmergencyRequest($data);
        $emergency->forceFill(['customer_user_id' => $user->id, 'priority' => $priority, 'sla_due_at' => now()->addMinutes(self::SLA_MINUTES[$priority])])->save();

        return back()->with('status', 'Your request has been received and marked urgent. If you are in immediate danger, call 112.');
    }

    // Staff queue

    public function queue(): View
    {
        return view('staff.emergency', [
            'requests' => EmergencyRequest::query()->with(['city', 'responder.advocateProfile.user'])
                ->whereNotIn('status', ['resolved', 'closed'])->orderBy('priority')->orderBy('sla_due_at')->paginate(25),
            'responders' => EmergencyResponder::query()->with('advocateProfile.user')->where('is_active', true)->get(),
        ]);
    }

    public function triage(Request $request, EmergencyRequest $emergency): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'assigned', 'referred', 'resolved', 'closed'])],
            'assigned_responder_id' => ['nullable', 'uuid', 'required_if:status,assigned', 'exists:emergency_responders,id'],
        ]);

        $changes = ['status' => $data['status']];
        if (! empty($data['assigned_responder_id'])) {
            $changes['assigned_responder_id'] = $data['assigned_responder_id'];
        }
        if ($emergency->acknowledged_at === null) {
            $changes['acknowledged_at'] = now();
        }
        if (in_array($data['status'], ['resolved', 'closed'], true)) {
            $changes['resolved_at'] = now();
        }
        $emergency->forceFill($changes)->save();

        return back()->with('status', 'Request updated.');
    }

    // Assigned responder

    public function assigned(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::citizenCan($user, 'emergency.respond'), 403);

        return view('emergency.assigned', [
            'requests' => EmergencyRequest::query()->with('city')
                ->whereIn('assigned_responder_id', EmergencyResponder::query()->whereIn('advocate_profile_id', $user->advocateProfile()->select('id'))->select('id'))
                ->whereIn('status', ['assigned', 'referred'])->latest()->get(),
        ]);
    }
}
