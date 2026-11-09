<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Disclaimer;
use App\Models\Document;
use App\Models\OdrDispute;
use App\Models\OdrMediatorAssignment;
use App\Models\OdrMessage;
use App\Models\OdrOffer;
use App\Models\OdrParticipant;
use App\Models\OdrSession;
use App\Models\OdrSettlement;
use App\Models\SettlementTemplate;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use App\Services\OdrService;
use App\Support\AccessContext;
use App\Support\OdrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** D8.13 and D5.5: not being part of a dispute always means 404. */
class OdrController extends Controller
{
    public function __construct(private OdrService $odr) {}

    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('odr.index', [
            'disputes' => OdrDispute::query()->whereIn('id', DB::table('odr_participants')->where(['user_id' => $user->id, 'status' => 'joined'])->select('dispute_id'))
                ->orWhereIn('id', DB::table('odr_mediator_assignments as a')->join('mediator_profiles as m', 'm.id', '=', 'a.mediator_profile_id')->where('m.user_id', $user->id)->where('a.status', 'accepted')->select('a.dispute_id'))
                ->latest()->get(),
            'proposed' => OdrMediatorAssignment::query()->with('dispute')->where('status', 'proposed')
                ->whereIn('mediator_profile_id', DB::table('mediator_profiles')->where('user_id', $user->id)->select('id'))->get(),
            'canCreate' => AccessContext::citizenCan($user, 'odr.disputes.create'),
            'canMediate' => AccessContext::citizenCan($user, 'odr.mediate'),
        ]);
    }

    public function create(): View
    {
        return view('odr.create', [
            'categories' => OdrDispute::CATEGORIES,
            'questions' => OdrService::QUESTIONS,
            'disclaimer' => Disclaimer::currentFor('odr'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(OdrDispute::CATEGORIES)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'claim_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'accept_disclaimer' => ['accepted'],
            'answers' => ['array'],
            'answers.*' => ['boolean'],
        ]);
        $answers = array_map('boolval', array_intersect_key($data['answers'] ?? [], OdrService::QUESTIONS));
        unset($data['accept_disclaimer'], $data['answers']);

        $dispute = DB::transaction(fn () => $this->odr->submit($this->odr->createDraft($this->user($request), $data), $answers));

        return redirect()->route('citizen.odr.show', $dispute)->with('status', match ($dispute->status) {
            'screening_failed' => 'This dispute isn\'t suitable for online resolution. Please speak to an advocate.',
            'screening' => 'A compliance officer will review your dispute first.',
            default => 'Your dispute is ready. Now invite the other party.',
        });
    }

    public function show(Request $request, OdrDispute $dispute): View
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::canView($user, $dispute), 404);
        $me = OdrAccess::participant($user, $dispute);
        $isMediator = OdrAccess::isMediator($user, $dispute);

        $settlement = OdrSettlement::query()->with('acceptances')->where('dispute_id', $dispute->id)->latest('version_number')->first();

        return view('odr.show', [
            'dispute' => $dispute,
            'me' => $me,
            'isMediator' => $isMediator,
            'isClaimant' => $dispute->claimant_user_id === $user->id,
            'participants' => OdrParticipant::query()->where('dispute_id', $dispute->id)->whereIn('status', ['invited', 'joined'])->get(),
            'messages' => OdrAccess::visibleMessages(OdrMessage::query(), $user, $dispute)->orderBy('created_at')->get(),
            'offers' => OdrOffer::query()->where('dispute_id', $dispute->id)->latest()->get(),
            'sessions' => OdrSession::query()->where('dispute_id', $dispute->id)->orderBy('scheduled_at')->get(),
            'settlement' => $settlement,
            'templates' => $isMediator ? SettlementTemplate::query()->where(['category' => $dispute->category, 'legal_review_status' => 'reviewed'])->get() : collect(),
            'screening' => $dispute->claimant_user_id === $user->id ? DB::table('odr_screenings')->where('dispute_id', $dispute->id)->orderByDesc('created_at')->first() : null,
            'canAcceptSettlement' => AccessContext::citizenCan($user, 'odr.settlements.accept'),
        ]);
    }

    public function inviteRespondent(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $this->claimantOnly($request, $dispute);
        abort_unless(in_array($dispute->status, ['submitted', 'awaiting_respondent', 'negotiation', 'mediation'], true), 422);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255'], 'organization_name' => ['nullable', 'string', 'max:200']]);
        $this->odr->inviteRespondent($dispute, $data['name'], $data['email'], $data['organization_name'] ?? null);

        return back()->with('status', 'Invitation sent.');
    }

    public function inviteCounsel(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $party = $this->participantOr404($request, $dispute);
        abort_unless(AccessContext::citizenCan($this->user($request), 'odr.participate'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255']]);
        $this->odr->inviteCounsel($dispute, $party, $data['name'], $data['email']);

        return back()->with('status', 'Invitation sent to your counsel.');
    }

    public function removeCounsel(Request $request, OdrDispute $dispute, OdrParticipant $participant): RedirectResponse
    {
        $this->odr->removeCounsel($dispute, $this->participantOr404($request, $dispute), $participant);

        return back()->with('status', 'Counsel removed.');
    }

    public function withdraw(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $this->claimantOnly($request, $dispute);
        $this->odr->withdraw($dispute);

        return back()->with('status', 'Dispute withdrawn.');
    }

    public function message(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::canView($user, $dispute), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'private_with' => ['nullable', 'uuid']]);
        $mediator = OdrAccess::mediatorProfileId($user, $dispute);
        $me = OdrAccess::participant($user, $dispute);

        // A party's "private" message goes to the mediator only, in that party's own caucus.
        $private = null;
        if ($mediator !== null && OdrAccess::isMediator($user, $dispute)) {
            if (! empty($data['private_with'])) {
                abort_unless(AccessContext::citizenCan($user, 'odr.caucus.post'), 403);
                abort_unless(OdrParticipant::query()->where(['id' => $data['private_with'], 'dispute_id' => $dispute->id, 'status' => 'joined'])->exists(), 422);
                $private = $data['private_with'];
            }
        } else {
            abort_unless(AccessContext::citizenCan($user, 'odr.participate') && $me !== null, 403);
            if ($request->boolean('caucus')) {
                abort_unless(OdrMessage::query()->where(['dispute_id' => $dispute->id, 'visibility' => 'mediator_private', 'private_with_participant_id' => $me->id])->exists(), 403, 'The mediator must open a private conversation first.');
                $private = $me->id;
            }
        }

        $this->odr->post($dispute, $me, $me === null ? $mediator : null, $data['body'], $private);

        return back()->with('status', 'Message sent.');
    }

    public function evidence(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::canView($user, $dispute), 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.evidence.upload'), 403);
        $data = $request->validate(['file' => UploadDocumentRequest::fileRules(), 'title' => ['required', 'string', 'max:200']]);
        $this->odr->addEvidence($dispute, $user, OdrAccess::participant($user, $dispute), OdrAccess::participant($user, $dispute) === null ? OdrAccess::mediatorProfileId($user, $dispute) : null, $request->file('file'), $data['title']);

        return back()->with('status', 'Evidence added. It can\'t be deleted.');
    }

    public function download(Request $request, OdrDispute $dispute, Document $document, DocumentVault $vault): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($document->odr_dispute_id === $dispute->id && OdrAccess::canSeeDocument($user, $document), 404);
        $version = $document->latestVersion();
        abort_if($version === null, 404);
        abort_if($version->isShredded(), 410);

        return $vault->download($document, $version, $user);
    }

    public function offer(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $party = $this->participantOr404($request, $dispute);
        abort_unless(AccessContext::citizenCan($this->user($request), 'odr.offers.make'), 403);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'terms' => ['required', 'string', 'max:2000']]);
        $this->odr->makeOffer($dispute, $party, isset($data['amount']) ? (string) $data['amount'] : null, $data['terms']);

        return back()->with('status', 'Offer sent.');
    }

    public function respondOffer(Request $request, OdrDispute $dispute, OdrOffer $offer): RedirectResponse
    {
        $party = $this->participantOr404($request, $dispute);
        abort_unless(AccessContext::citizenCan($this->user($request), 'odr.offers.make'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])]]);
        $this->odr->respondToOffer($dispute, $party, $offer, $data['decision'] === 'accept');

        return back()->with('status', $data['decision'] === 'accept' ? 'Offer accepted. The mediator will prepare the settlement.' : 'Offer rejected.');
    }

    public function respondAssignment(Request $request, OdrMediatorAssignment $assignment): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($assignment->mediator?->user_id === $user->id, 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.mediate'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline'])], 'conflict_disclosure' => ['required', 'string', 'max:2000']]);
        $this->odr->respondToAssignment($assignment, $data['decision'] === 'accept', $data['conflict_disclosure']);

        return back()->with('status', 'Your answer has been recorded.');
    }

    public function schedule(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        /** @var array{scheduled_at: string, duration_minutes: int|string, mode: string} $data */
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'], 'mode' => ['required', Rule::in(['video', 'audio', 'in_person'])]]);
        $this->odr->scheduleSession($dispute, $data);

        return back()->with('status', 'Session scheduled.');
    }

    public function updateSession(Request $request, OdrDispute $dispute, OdrSession $session): RedirectResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        abort_unless($session->dispute_id === $dispute->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['held', 'cancelled', 'no_show'])], 'summary' => ['nullable', 'string', 'max:5000']]);
        $session->forceFill($data)->save();

        return back()->with('status', 'Session updated.');
    }

    public function generate(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.settlements.generate');
        $data = $request->validate(['template_id' => ['required', 'uuid'], 'terms' => ['required', 'string', 'max:10000']]);
        $template = SettlementTemplate::query()->find($data['template_id']);
        abort_if($template === null, 422);
        $this->odr->generateSettlement($dispute, $this->user($request), $template, $data['terms']);

        return back()->with('status', 'The unsigned settlement agreement is ready for the parties to review.');
    }

    public function voidSettlement(Request $request, OdrDispute $dispute, OdrSettlement $settlement): RedirectResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.settlements.generate');
        $this->odr->voidSettlement($dispute, $settlement);

        return back()->with('status', 'Settlement voided.');
    }

    public function close(Request $request, OdrDispute $dispute): RedirectResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        $data = $request->validate(['closure_reason' => ['required', 'string', 'max:200']]);
        $this->odr->closeWithoutSettlement($dispute, $data['closure_reason']);

        return back()->with('status', 'Dispute closed.');
    }

    public function respondSettlement(Request $request, OdrDispute $dispute, OdrSettlement $settlement): RedirectResponse
    {
        $party = $this->participantOr404($request, $dispute);
        abort_unless(AccessContext::citizenCan($this->user($request), 'odr.settlements.accept') && $party->isParty(), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline'])]]);
        $this->odr->respondToSettlement($dispute, $settlement, $party, $data['decision'] === 'accept', $request->ip());

        return back()->with('status', 'Your answer has been recorded.');
    }

    // Invitations. The token only ever joins this person to this dispute (B3-5).

    public function invitation(string $token): View
    {
        $participant = $this->odr->participantForToken($token);
        abort_if($participant === null, 404);

        return view('odr.invitation', ['participant' => $participant, 'token' => $token, 'dispute' => $participant->dispute, 'disclaimer' => Disclaimer::currentFor('odr')]);
    }

    public function acceptInvitation(Request $request, string $token): RedirectResponse
    {
        $participant = $this->odr->participantForToken($token);
        abort_if($participant === null, 404);
        $dispute = $this->odr->acceptInvitation($participant, $this->user($request));

        return redirect()->route('citizen.odr.show', $dispute)->with('status', 'You have joined this dispute.');
    }

    public function declineInvitation(Request $request, string $token): RedirectResponse
    {
        $participant = $this->odr->participantForToken($token);
        abort_if($participant === null, 404);
        $this->odr->declineInvitation($participant, $this->user($request));

        return redirect()->route('citizen.dashboard')->with('status', 'You have declined the invitation.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function participantOr404(Request $request, OdrDispute $dispute): OdrParticipant
    {
        $me = OdrAccess::participant($this->user($request), $dispute);
        abort_if($me === null, 404);

        return $me;
    }

    private function claimantOnly(Request $request, OdrDispute $dispute): void
    {
        $user = $this->user($request);
        abort_unless($dispute->claimant_user_id === $user->id && AccessContext::citizenCan($user, 'odr.disputes.create'), 404);
    }

    private function mediatorOnly(Request $request, OdrDispute $dispute, string $permission): void
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::isMediator($user, $dispute), 404);
        abort_unless(AccessContext::citizenCan($user, $permission), 403);
    }
}
