<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\OdrDispute;
use App\Models\OdrMessage;
use App\Models\OdrOffer;
use App\Models\OdrParticipant;
use App\Models\OdrSettlement;
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

/** ODR for mobile parties. Mediator tools remain web-only for now. */
class OdrController extends Controller
{
    public function __construct(private OdrService $odr) {}

    public function index(Request $request): JsonResponse
    {
        $disputes = OdrDispute::query()->whereIn('id', DB::table('odr_participants')->where(['user_id' => $this->user($request)->id, 'status' => 'joined'])->select('dispute_id'))->latest()->paginate(25);

        return response()->json($disputes->through(fn (OdrDispute $d) => $this->summary($d)));
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

        return response()->json($this->summary($dispute) + [
            'description' => $dispute->description,
            'participants' => OdrParticipant::query()->where('dispute_id', $dispute->id)->whereIn('status', ['invited', 'joined'])->get(['id', 'name', 'participant_role', 'status']),
            'messages' => OdrAccess::visibleMessages(OdrMessage::query(), $user, $dispute)->orderBy('created_at')->get()
                ->map(fn (OdrMessage $m) => ['id' => $m->id, 'type' => $m->message_type, 'visibility' => $m->visibility, 'sender_participant_id' => $m->sender_participant_id, 'body' => $m->body, 'document_id' => $m->document_id, 'created_at' => $m->created_at]),
            'offers' => OdrOffer::query()->where('dispute_id', $dispute->id)->latest()->get(['id', 'offered_by_participant_id', 'amount', 'terms', 'status']),
            'settlement' => OdrSettlement::query()->where('dispute_id', $dispute->id)->latest('version_number')->first(['id', 'version_number', 'terms', 'status', 'document_id']),
            'settlement_label' => 'Settlement agreement (unsigned)',
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

    public function message(Request $request, OdrDispute $dispute): JsonResponse
    {
        $user = $this->user($request);
        $me = OdrAccess::participant($user, $dispute);
        abort_if($me === null, 404);
        abort_unless(AccessContext::citizenCan($user, 'odr.participate'), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return response()->json(['id' => $this->odr->post($dispute, $me, null, $data['body'])->id], 201);
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

    public function download(Request $request, OdrDispute $dispute, Document $document, DocumentVault $vault): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($document->odr_dispute_id === $dispute->id && OdrAccess::canSeeDocument($user, $document), 404);
        $version = $document->latestVersion();
        abort_if($version === null, 404);
        abort_if($version->isShredded(), 410);

        return $vault->download($document, $version, $user);
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
