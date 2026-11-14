<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Integrations\Contracts\VideoProvider;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ODR for mobile parties and mediators. Same rules as the web screens (B3, D8.13). */
class OdrController extends Controller
{
    public function __construct(private OdrService $odr) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $disputes = OdrDispute::query()->whereIn('id', DB::table('odr_participants')->where(['user_id' => $user->id, 'status' => 'joined'])->select('dispute_id'))
            ->orWhereIn('id', DB::table('odr_mediator_assignments as a')->join('mediator_profiles as m', 'm.id', '=', 'a.mediator_profile_id')->where('m.user_id', $user->id)->where('a.status', 'accepted')->select('a.dispute_id'))
            ->latest()->paginate(25);

        return response()->json($disputes->through(fn (OdrDispute $d) => $this->summary($d)));
    }

    /** Mediator: disputes proposed to me that I haven't answered yet. */
    public function assignments(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::citizenCan($user, 'odr.mediate'), 403);

        return response()->json(['data' => OdrMediatorAssignment::query()->with('dispute')->where('status', 'proposed')
            ->whereIn('mediator_profile_id', DB::table('mediator_profiles')->where('user_id', $user->id)->select('id'))->get()
            ->map(fn (OdrMediatorAssignment $a) => ['id' => $a->id, 'dispute' => $a->dispute === null ? null : $this->summary($a->dispute)])]);
    }

    public function store(Request $request): JsonResponse
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

        return response()->json($this->summary($dispute), 201);
    }

    public function show(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::canView($user, $dispute), 404);
        $isMediator = OdrAccess::isMediator($user, $dispute);

        return response()->json($this->summary($dispute) + [
            'description' => $dispute->description,
            'my_participant_id' => OdrAccess::participant($user, $dispute)?->id,
            'is_mediator' => $isMediator,
            'participants' => OdrParticipant::query()->where('dispute_id', $dispute->id)->whereIn('status', ['invited', 'joined'])->get(['id', 'name', 'participant_role', 'status']),
            'messages' => OdrAccess::visibleMessages(OdrMessage::query(), $user, $dispute)->orderBy('created_at')->get()
                ->map(fn (OdrMessage $m) => ['id' => $m->id, 'type' => $m->message_type, 'visibility' => $m->visibility, 'sender_participant_id' => $m->sender_participant_id,
                    'from_mediator' => $m->sender_mediator_profile_id !== null, 'private_with_participant_id' => $m->private_with_participant_id, 'body' => $m->body, 'document_id' => $m->document_id, 'created_at' => $m->created_at]),
            'offers' => OdrOffer::query()->where('dispute_id', $dispute->id)->latest()->get(['id', 'offered_by_participant_id', 'amount', 'terms', 'status']),
            'sessions' => OdrSession::query()->where('dispute_id', $dispute->id)->orderBy('scheduled_at')->get(['id', 'scheduled_at', 'duration_minutes', 'mode', 'status', 'meeting_ref']),
            'settlement' => OdrSettlement::query()->where('dispute_id', $dispute->id)->latest('version_number')->first(['id', 'version_number', 'terms', 'status', 'document_id']),
            'settlement_label' => 'Settlement agreement (unsigned)',
            'templates' => $isMediator ? SettlementTemplate::query()->where(['category' => $dispute->category, 'legal_review_status' => 'reviewed'])->get(['id', 'title', 'version']) : [],
        ]);
    }

    public function invite(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($dispute->claimant_user_id === $user->id && AccessContext::citizenCan($user, 'odr.disputes.create'), 404);
        abort_unless(in_array($dispute->status, ['awaiting_respondent', 'negotiation', 'mediation'], true), 422);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255'], 'organization_name' => ['nullable', 'string', 'max:200']]);
        $this->odr->inviteRespondent($dispute, $data['name'], $data['email'], $data['organization_name'] ?? null);

        return response()->json(['message' => 'Invitation sent.'], 201);
    }

    /** The token joins only this person to this dispute (B3-5). */
    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $participant = $this->odr->participantForToken($token);
        abort_if($participant === null, 404);

        return response()->json($this->summary($this->odr->acceptInvitation($participant, $this->user($request))));
    }

    public function declineInvitation(Request $request, string $token): JsonResponse
    {
        $participant = $this->odr->participantForToken($token);
        abort_if($participant === null, 404);
        $this->odr->declineInvitation($participant, $this->user($request));

        return response()->json(['message' => 'Invitation declined.']);
    }

    /**
     * Parties post to everyone, or with caucus=true to the mediator only once the mediator
     * has opened a private conversation. Mediators post to everyone, or privately with private_with.
     */
    public function message(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::canView($user, $dispute), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'private_with' => ['nullable', 'uuid'], 'caucus' => ['sometimes', 'boolean']]);
        $mediator = OdrAccess::mediatorProfileId($user, $dispute);
        $me = OdrAccess::participant($user, $dispute);

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

        return response()->json(['id' => $this->odr->post($dispute, $me, $me === null ? $mediator : null, $data['body'], $private)->id], 201);
    }

    /** Evidence is encrypted, visible to the dispute, and can't be deleted. */
    public function evidence(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::canView($user, $dispute), 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.evidence.upload'), 403);
        $data = $request->validate(['file' => UploadDocumentRequest::fileRules(), 'title' => ['required', 'string', 'max:200']]);
        $me = OdrAccess::participant($user, $dispute);
        $message = $this->odr->addEvidence($dispute, $user, $me, $me === null ? OdrAccess::mediatorProfileId($user, $dispute) : null, $request->file('file'), $data['title']);

        return response()->json(['id' => $message->id, 'document_id' => $message->document_id], 201);
    }

    public function offer(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        $me = OdrAccess::participant($user, $dispute);
        abort_if($me === null, 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.offers.make'), 403);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'terms' => ['required', 'string', 'max:2000']]);
        $offer = $this->odr->makeOffer($dispute, $me, isset($data['amount']) ? (string) $data['amount'] : null, $data['terms']);

        return response()->json($offer->only(['id', 'status']), 201);
    }

    public function respondOffer(Request $request, OdrDispute $dispute, OdrOffer $offer): JsonResponse
    {
        $user = $this->user($request);
        $me = OdrAccess::participant($user, $dispute);
        abort_if($me === null, 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.offers.make'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])]]);
        $this->odr->respondToOffer($dispute, $me, $offer, $data['decision'] === 'accept');

        return response()->json($this->summary($dispute->fresh() ?? $dispute));
    }

    public function respondSettlement(Request $request, OdrDispute $dispute, OdrSettlement $settlement): JsonResponse
    {
        $user = $this->user($request);
        $me = OdrAccess::participant($user, $dispute);
        abort_if($me === null, 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.settlements.accept') && $me->isParty(), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline'])]]);
        $this->odr->respondToSettlement($dispute, $settlement, $me, $data['decision'] === 'accept', $request->ip());

        return response()->json($this->summary($dispute->fresh() ?? $dispute));
    }

    // Mediator tools

    public function respondAssignment(Request $request, OdrMediatorAssignment $assignment): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($assignment->mediator?->user_id === $user->id, 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.mediate'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline'])], 'conflict_disclosure' => ['required', 'string', 'max:2000']]);
        $this->odr->respondToAssignment($assignment, $data['decision'] === 'accept', $data['conflict_disclosure']);

        return response()->json(['status' => $assignment->fresh()?->status]);
    }

    public function schedule(Request $request, OdrDispute $dispute): JsonResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        /** @var array{scheduled_at: string, duration_minutes: int|string, mode: string} $data */
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'], 'mode' => ['required', Rule::in(['video', 'audio', 'in_person'])]]);

        return response()->json($this->odr->scheduleSession($dispute, $data)->only(['id', 'scheduled_at', 'duration_minutes', 'mode', 'status']), 201);
    }

    public function updateSession(Request $request, OdrDispute $dispute, OdrSession $session): JsonResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        abort_unless($session->dispute_id === $dispute->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['held', 'cancelled', 'no_show'])], 'summary' => ['nullable', 'string', 'max:5000']]);
        $session->forceFill($data)->save();

        return response()->json($session->only(['id', 'status']));
    }

    public function generate(Request $request, OdrDispute $dispute): JsonResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.settlements.generate');
        $data = $request->validate(['template_id' => ['required', 'uuid'], 'terms' => ['required', 'string', 'max:10000']]);
        $template = SettlementTemplate::query()->find($data['template_id']);
        abort_if($template === null, 422);
        $settlement = $this->odr->generateSettlement($dispute, $this->user($request), $template, $data['terms']);

        return response()->json($settlement->only(['id', 'version_number', 'status', 'document_id']) + ['label' => 'Settlement agreement (unsigned)'], 201);
    }

    public function voidSettlement(Request $request, OdrDispute $dispute, OdrSettlement $settlement): JsonResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.settlements.generate');
        $this->odr->voidSettlement($dispute, $settlement);

        return response()->json(['status' => $settlement->fresh()?->status]);
    }

    public function close(Request $request, OdrDispute $dispute): JsonResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        $data = $request->validate(['closure_reason' => ['required', 'string', 'max:200']]);
        $this->odr->closeWithoutSettlement($dispute, $data['closure_reason']);

        return response()->json($this->summary($dispute->fresh() ?? $dispute));
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

    /** A party invites their own counsel by email. */
    public function inviteCounsel(Request $request, OdrDispute $dispute): JsonResponse
    {
        $party = $this->participantOr404($request, $dispute);
        abort_unless(AccessContext::citizenCan($this->user($request), 'odr.participate'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255']]);
        $this->odr->inviteCounsel($dispute, $party, $data['name'], $data['email']);

        return response()->json(['message' => 'Invitation sent to your counsel.'], 201);
    }

    public function removeCounsel(Request $request, OdrDispute $dispute, OdrParticipant $participant): JsonResponse
    {
        $this->odr->removeCounsel($dispute, $this->participantOr404($request, $dispute), $participant);

        return response()->json(['message' => 'Counsel removed.']);
    }

    /** The claimant withdraws the dispute. */
    public function withdraw(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($dispute->claimant_user_id === $user->id && AccessContext::citizenCan($user, 'odr.disputes.create'), 404);
        $this->odr->withdraw($dispute);

        return response()->json($this->summary($dispute->fresh() ?? $dispute));
    }

    /** Step 23. 503 until a video service is switched on. */
    public function meeting(Request $request, OdrDispute $dispute, OdrSession $session, VideoProvider $video): JsonResponse
    {
        $this->mediatorOnly($request, $dispute, 'odr.sessions.manage');
        abort_unless($session->dispute_id === $dispute->id && $session->status === 'scheduled', 404);
        $session->forceFill(['meeting_ref' => $video->createMeeting($session)])->save();

        return response()->json(['session_id' => $session->id, 'meeting_ref' => $session->meeting_ref], 201);
    }

    private function participantOr404(Request $request, OdrDispute $dispute): OdrParticipant
    {
        $me = OdrAccess::participant($this->user($request), $dispute);
        abort_if($me === null, 404);

        return $me;
    }

    private function mediatorOnly(Request $request, OdrDispute $dispute, string $permission): void
    {
        $user = $this->user($request);
        abort_unless(OdrAccess::isMediator($user, $dispute), 404);
        abort_unless(AccessContext::citizenCan($user, $permission), 403);
    }

    /** @return array<string, mixed> */
    private function summary(OdrDispute $dispute): array
    {
        return $dispute->only(['id', 'dispute_number', 'category', 'title', 'claim_amount', 'status', 'negotiation_deadline', 'closed_at', 'closure_reason']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
