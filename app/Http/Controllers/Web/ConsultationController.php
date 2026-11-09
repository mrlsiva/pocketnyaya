<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdvocateProfile;
use App\Models\ConsultationRequest;
use App\Models\User;
use App\Services\ConsultationService;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The customer side uses citizen context, and the practice side uses tenant context (D5.3). */
class ConsultationController extends Controller
{
    // Customer side

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('consultations.index', [
            'heading' => 'My consultations',
            'consultations' => ConsultationRequest::query()->with('advocateProfile.user')->where('customer_user_id', $user->id)->latest()->paginate(25),
            'showRoute' => 'citizen.consultations.show',
            'nav' => null,
        ]);
    }

    public function create(string $advocate): View
    {
        $profile = AdvocateProfile::query()->publiclyListed()->with('user:id,name')->find($advocate);
        abort_if($profile === null, 404);

        return view('consultations.create', ['advocate' => $profile]);
    }

    public function store(Request $request, string $advocate, ConsultationService $consultations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = AdvocateProfile::query()->publiclyListed()->find($advocate);
        abort_if($profile === null, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['chat', 'video', 'call', 'in_person'])],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
        ]);

        $consultation = $consultations->request($user, $profile, $data);

        return redirect()->route('citizen.consultations.show', $consultation)->with('status', 'Your request has been sent.');
    }

    public function showCustomer(Request $request, ConsultationRequest $consultation): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($consultation->customer_user_id === $user->id, 404);

        return view('consultations.show', [
            'consultation' => $consultation->load('advocateProfile.user'),
            'messages' => $consultation->messages()->with('sender:id,name')->oldest()->get(),
            'messageRoute' => route('citizen.consultations.messages', $consultation),
            'isPractice' => false,
            'nav' => null,
        ]);
    }

    public function messageCustomer(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($consultation->customer_user_id === $user->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $consultations->message($consultation, $user, $data['body']);

        return back();
    }

    public function cancel(Request $request, ConsultationRequest $consultation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($consultation->customer_user_id === $user->id, 404);
        abort_unless(in_array($consultation->status, ['requested', 'accepted', 'scheduled'], true), 422);
        $consultation->forceFill(['status' => 'cancelled'])->save();

        return back()->with('status', 'Consultation cancelled.');
    }

    // Practice side

    public function inbox(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $query = ConsultationRequest::query()->with('customer:id,name')->where('tenant_id', AccessContext::currentTenantId());
        if (! AccessContext::tenantCan($user, 'consultations.inbox.view_all')) {
            $query->where('advocate_membership_id', MatterAccess::me($user));
        }

        return view('consultations.index', [
            'heading' => 'Consultation requests',
            'consultations' => $query->latest()->paginate(25),
            'showRoute' => 'practice.consultations.show',
            'nav' => 'practice.nav',
        ]);
    }

    public function showPractice(Request $request, ConsultationRequest $consultation): View
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeTenantView($user, $consultation);

        $isAddressee = $consultation->advocate_membership_id === MatterAccess::me($user);

        return view('consultations.show', [
            'consultation' => $consultation->load('customer:id,name'),
            'messages' => $consultation->messages()->with('sender:id,name')->oldest()->get(),
            'messageRoute' => route('practice.consultations.messages', $consultation),
            'isPractice' => true,
            'nav' => 'practice.nav',
            'canRespond' => $this->canRespond($user, $consultation),
            'canConvert' => AccessContext::tenantCan($user, 'consultations.convert') && in_array($consultation->status, ['accepted', 'completed'], true)
                && ($isAddressee || AccessContext::tenantCan($user, 'consultations.inbox.view_all')),
            'canNotes' => $isAddressee && AccessContext::tenantCan($user, 'consultations.private_notes.manage_own'),
        ]);
    }

    public function respond(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeTenantView($user, $consultation);
        abort_unless($this->canRespond($user, $consultation), 403);
        $data = $request->validate(['status' => ['required', 'string'], 'scheduled_at' => ['nullable', 'date', 'after:now']]);
        $consultations->respond($consultation, $data['status'], $data['scheduled_at'] ?? null);

        return back()->with('status', 'Consultation updated.');
    }

    public function messagePractice(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeTenantView($user, $consultation);
        abort_unless($this->canRespond($user, $consultation), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $consultations->message($consultation, $user, $data['body']);

        return back();
    }

    public function notes(Request $request, ConsultationRequest $consultation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($consultation->tenant_id === AccessContext::currentTenantId() && $consultation->advocate_membership_id === MatterAccess::me($user), 404);
        abort_unless(AccessContext::tenantCan($user, 'consultations.private_notes.manage_own'), 403);
        $data = $request->validate(['lawyer_private_notes' => ['nullable', 'string', 'max:10000']]);
        $consultation->forceFill(['lawyer_private_notes' => $data['lawyer_private_notes'] ?? null])->save();

        return back()->with('status', 'Private notes saved.');
    }

    public function convert(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeTenantView($user, $consultation);
        $isAddressee = $consultation->advocate_membership_id === MatterAccess::me($user);
        abort_unless(AccessContext::tenantCan($user, 'consultations.convert') && ($isAddressee || AccessContext::tenantCan($user, 'consultations.inbox.view_all')), 403);

        $matter = $consultations->convert($consultation, $user);

        return redirect()->route('practice.matters.show', $matter)->with('status', 'Matter created from this consultation.');
    }

    private function authorizeTenantView(User $user, ConsultationRequest $consultation): void
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
}
