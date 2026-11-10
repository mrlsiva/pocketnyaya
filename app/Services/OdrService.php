<?php

namespace App\Services;

use App\Models\Disclaimer;
use App\Models\MediatorProfile;
use App\Models\OdrDispute;
use App\Models\OdrMediatorAssignment;
use App\Models\OdrMessage;
use App\Models\OdrOffer;
use App\Models\OdrParticipant;
use App\Models\OdrSession;
use App\Models\OdrSettlement;
use App\Models\SettlementAcceptance;
use App\Models\SettlementTemplate;
use App\Models\User;
use App\Notifications\OdrInvitationNotification;
use App\Services\Documents\DocumentService;
use App\Support\AccessContext;
use App\Support\Realtime;
use App\Support\SimplePdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OdrService
{
    /** Provisional suitability rules. Counsel must review them before launch. */
    public const RULES_VERSION = 'provisional-1';

    public const QUESTIONS = [
        'involves_violence' => 'Does this involve violence, threats or abuse?',
        'involves_crime' => 'Does this involve a criminal allegation?',
        'needs_urgent_order' => 'Do you urgently need a court order?',
        'in_court_already' => 'Is this dispute already before a court or tribunal?',
    ];

    public function __construct(
        private RoleAssignmentService $roles,
        private DocumentService $documents,
        private ScreeningService $screening,
    ) {}

    /** @param array<string, mixed> $data */
    public function createDraft(User $user, array $data): OdrDispute
    {
        return DB::transaction(function () use ($user, $data) {
            $dispute = new OdrDispute($data);
            $dispute->forceFill(['claimant_user_id' => $user->id, 'status' => 'draft'])->save();

            $claimant = new OdrParticipant(['name' => $user->name, 'email' => (string) $user->email]);
            $claimant->forceFill(['dispute_id' => $dispute->id, 'user_id' => $user->id, 'participant_role' => 'claimant', 'status' => 'joined', 'invited_at' => now(), 'joined_at' => now()])->save();

            $disclaimer = Disclaimer::currentFor('odr');
            if ($disclaimer !== null) {
                DB::table('disclaimer_acceptances')->insert(['id' => (string) Str::uuid7(), 'disclaimer_id' => $disclaimer->id, 'user_id' => $user->id, 'ip' => request()->ip(), 'user_agent' => Str::limit((string) request()->userAgent(), 500, ''), 'created_at' => now()]);
            }

            return $dispute;
        });
    }

    /**
     * Screens the dispute. Violence or a criminal allegation is unsuitable. An urgent
     * order or an existing court case goes to a Compliance Officer.
     *
     * @param  array<string, bool>  $answers
     */
    public function submit(OdrDispute $dispute, array $answers): OdrDispute
    {
        $this->assertStatus($dispute, ['draft']);
        $reasons = [];
        $result = 'suitable';
        if (! empty($answers['involves_violence']) || ! empty($answers['involves_crime'])) {
            $result = 'unsuitable';
            $reasons[] = 'involves_violence_or_crime';
        } elseif (! empty($answers['needs_urgent_order']) || ! empty($answers['in_court_already'])) {
            $result = 'needs_review';
            $reasons[] = 'urgent_or_already_in_court';
        }

        return DB::transaction(function () use ($dispute, $answers, $result, $reasons) {
            DB::table('odr_screenings')->insert([
                'id' => (string) Str::uuid7(), 'dispute_id' => $dispute->id, 'questionnaire' => json_encode($answers), 'rules_version' => self::RULES_VERSION,
                'result' => $result, 'reasons' => json_encode($reasons), 'created_at' => now(),
            ]);

            $dispute->forceFill([
                'dispute_number' => $dispute->dispute_number ?? 'ODR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
                'status' => match ($result) {
                    'suitable' => 'awaiting_respondent',
                    'unsuitable' => 'screening_failed',
                    default => 'screening',
                },
                'closed_at' => $result === 'unsuitable' ? now() : null,
                'closure_reason' => $result === 'unsuitable' ? 'Not suitable for online resolution.' : null,
            ])->save();

            return $dispute;
        });
    }

    public function review(OdrDispute $dispute, User $reviewer, string $result, string $note): void
    {
        $this->assertStatus($dispute, ['screening']);

        DB::transaction(function () use ($dispute, $reviewer, $result, $note) {
            DB::table('odr_screenings')->insert([
                'id' => (string) Str::uuid7(), 'dispute_id' => $dispute->id, 'questionnaire' => json_encode(['review' => true]), 'rules_version' => self::RULES_VERSION,
                'result' => $result, 'reasons' => json_encode([$note]), 'reviewed_by' => $reviewer->id, 'created_at' => now(),
            ]);
            $suitable = $result === 'suitable';
            $dispute->forceFill(['status' => $suitable ? 'awaiting_respondent' : 'screening_failed', 'closed_at' => $suitable ? null : now(), 'closure_reason' => $suitable ? null : 'Not suitable for online resolution.'])->save();
            app(AuditLogWriter::class)->record('odr.screening_reviewed', 'odr_dispute', $dispute->id, null, null, ['result' => $result]);
        });
    }

    public function inviteRespondent(OdrDispute $dispute, string $name, string $email, ?string $organization): void
    {
        $this->assertOpen($dispute);
        if (OdrParticipant::query()->where('dispute_id', $dispute->id)->where('participant_role', 'respondent')->whereIn('status', ['invited', 'joined'])->exists()) {
            throw ValidationException::withMessages(['email' => 'The other party has already been invited.']);
        }
        $this->invite($dispute, 'respondent', $name, $email, $organization);
    }

    public function inviteCounsel(OdrDispute $dispute, OdrParticipant $party, string $name, string $email): void
    {
        $this->assertOpen($dispute);
        if (! $party->isParty()) {
            throw ValidationException::withMessages(['email' => 'Only a party can invite their own counsel.']);
        }
        $role = $party->participant_role.'_counsel';
        if (OdrParticipant::query()->where('dispute_id', $dispute->id)->where('participant_role', $role)->whereIn('status', ['invited', 'joined'])->exists()) {
            throw ValidationException::withMessages(['email' => 'You already have counsel on this dispute. Remove them first.']);
        }
        $this->invite($dispute, $role, $name, $email, null);
    }

    public function removeCounsel(OdrDispute $dispute, OdrParticipant $party, OdrParticipant $counsel): void
    {
        $this->assertOpen($dispute);
        abort_unless($counsel->dispute_id === $dispute->id && $counsel->participant_role === $party->participant_role.'_counsel', 404);
        $counsel->forceFill(['status' => 'removed', 'invitation_token_hash' => null])->save();
    }

    public function participantForToken(string $token): ?OdrParticipant
    {
        return OdrParticipant::query()->where('invitation_token_hash', hash('sha256', $token))->where('status', 'invited')
            ->where('invitation_expires_at', '>', now())->first();
    }

    /** B3-5 and B3-9: the token joins this person to this dispute, and does nothing else. */
    public function acceptInvitation(OdrParticipant $participant, User $user): OdrDispute
    {
        if (mb_strtolower((string) $user->email) !== mb_strtolower($participant->email)) {
            abort(403, 'This invitation was sent to a different email address.');
        }
        $isCounsel = str_ends_with($participant->participant_role, '_counsel');
        if ($isCounsel && ! AccessContext::citizenCan($user, 'odr.counsel.participate')) {
            abort(403, 'Only a verified advocate can join as counsel.');
        }
        if (OdrParticipant::query()->where(['dispute_id' => $participant->dispute_id, 'user_id' => $user->id])->exists()) {
            throw ValidationException::withMessages(['invitation' => 'You are already part of this dispute.']);
        }

        return DB::transaction(function () use ($participant, $user, $isCounsel) {
            $participant->forceFill(['user_id' => $user->id, 'status' => 'joined', 'joined_at' => now(), 'invitation_token_hash' => null, 'invitation_expires_at' => null])->save();
            /** @var OdrDispute $dispute */
            $dispute = OdrDispute::query()->lockForUpdate()->findOrFail($participant->dispute_id);

            if (! $isCounsel) {
                $this->roles->assign($user, 'ODR Respondent', (string) AccessContext::platformTenantId());
                if ($dispute->status === 'awaiting_respondent') {
                    $dispute->forceFill(['status' => 'negotiation', 'negotiation_deadline' => now()->addDays(30)])->save();
                }
            }

            return $dispute;
        });
    }

    public function declineInvitation(OdrParticipant $participant, User $user): void
    {
        if (mb_strtolower((string) $user->email) !== mb_strtolower($participant->email)) {
            abort(403, 'This invitation was sent to a different email address.');
        }

        DB::transaction(function () use ($participant) {
            $participant->forceFill(['status' => 'declined', 'invitation_token_hash' => null, 'invitation_expires_at' => null])->save();
            if ($participant->participant_role === 'respondent') {
                $participant->dispute?->forceFill(['status' => 'closed_no_settlement', 'closed_at' => now(), 'closure_reason' => 'The other party declined to take part.'])->save();
            }
        });
    }

    public function withdraw(OdrDispute $dispute): void
    {
        $this->assertOpen($dispute);
        if ($dispute->status === 'settlement_pending_acceptance') {
            throw ValidationException::withMessages(['status' => 'Decline the settlement first.']);
        }
        $dispute->forceFill(['status' => 'withdrawn', 'closed_at' => now(), 'closure_reason' => 'Withdrawn by the claimant.'])->save();
    }

    /**
     * Messages are permanent. Tone problems are noted for the mediator, and never stop a party
     * speaking. Parties may share contact details with each other here, so nothing is blocked.
     */
    public function post(OdrDispute $dispute, ?OdrParticipant $sender, ?string $mediatorProfileId, string $body, ?string $privateWith = null, string $type = 'text', ?string $documentId = null): OdrMessage
    {
        $this->assertWritable($dispute);
        $check = $this->screening->check($body, 'odr');
        $toneOnly = array_filter($check['matched'], fn ($m) => $m['category'] === 'hostile_tone');

        $message = new OdrMessage;
        $message->forceFill([
            'dispute_id' => $dispute->id,
            'sender_participant_id' => $sender?->id,
            'sender_mediator_profile_id' => $mediatorProfileId,
            'message_type' => $type,
            'visibility' => $privateWith === null ? 'all_parties' : 'mediator_private',
            'private_with_participant_id' => $privateWith,
            'body' => $body,
            'document_id' => $documentId,
            'tone_check' => $toneOnly === [] ? null : array_values($toneOnly),
            'content_hash' => hash('sha256', $body),
        ])->save();
        $this->signalMessage($dispute, $sender, $privateWith);

        return $message;
    }

    /** Real-time signal to everyone who can read the message, except its sender. Ids only. */
    private function signalMessage(OdrDispute $dispute, ?OdrParticipant $sender, ?string $privateWith): void
    {
        $participants = OdrParticipant::query()->where(['dispute_id' => $dispute->id, 'status' => 'joined'])
            ->when($privateWith !== null, fn ($q) => $q->whereKey($privateWith))->pluck('user_id')->all();
        $mediators = DB::table('odr_mediator_assignments as a')->join('mediator_profiles as m', 'm.id', '=', 'a.mediator_profile_id')
            ->where(['a.dispute_id' => $dispute->id, 'a.status' => 'accepted'])->pluck('m.user_id')->all();
        foreach (array_unique(array_filter(array_merge($participants, $mediators))) as $userId) {
            if ($userId !== $sender?->user_id) {
                Realtime::toUser((string) $userId, 'odr.message', ['dispute_id' => $dispute->id]);
            }
        }
    }

    public function addEvidence(OdrDispute $dispute, User $user, ?OdrParticipant $sender, ?string $mediatorProfileId, UploadedFile $file, string $title, ?string $privateWith = null): OdrMessage
    {
        $this->assertWritable($dispute);

        return DB::transaction(function () use ($dispute, $user, $sender, $mediatorProfileId, $file, $title, $privateWith) {
            $document = $this->documents->create($user, $file, ['title' => $title, 'category' => 'evidence', 'confidentiality' => 'confidential'], ['scope' => 'platform', 'odr_dispute_id' => $dispute->id]);

            return $this->post($dispute, $sender, $mediatorProfileId, 'Evidence added: '.$title, $privateWith, 'evidence', $document->id);
        });
    }

    public function makeOffer(OdrDispute $dispute, OdrParticipant $party, ?string $amount, string $terms): OdrOffer
    {
        $this->assertWritable($dispute);
        if (! $party->isParty()) {
            abort(403, 'Only a party can make an offer.');
        }

        return DB::transaction(function () use ($dispute, $party, $amount, $terms) {
            // A new offer from the other side counters whatever was open.
            $countering = OdrOffer::query()->where(['dispute_id' => $dispute->id, 'status' => 'open'])->where('offered_by_participant_id', '<>', $party->id)->lockForUpdate()->get();
            OdrOffer::query()->where(['dispute_id' => $dispute->id, 'status' => 'open', 'offered_by_participant_id' => $party->id])->update(['status' => 'withdrawn', 'responded_at' => now()]);
            foreach ($countering as $old) {
                $old->forceFill(['status' => 'countered', 'responded_at' => now()])->save();
            }

            $message = $this->post($dispute, $party, null, trim(($amount === null ? '' : "Offer of Rs {$amount}. ").$terms), null, $countering->isEmpty() ? 'offer' : 'counter_offer');
            $offer = new OdrOffer;
            $offer->forceFill(['dispute_id' => $dispute->id, 'message_id' => $message->id, 'offered_by_participant_id' => $party->id, 'amount' => $amount, 'terms' => ['text' => $terms], 'status' => 'open'])->save();

            return $offer;
        });
    }

    public function respondToOffer(OdrDispute $dispute, OdrParticipant $party, OdrOffer $offer, bool $accept): void
    {
        $this->assertWritable($dispute);
        abort_unless($offer->dispute_id === $dispute->id, 404);
        if (! $party->isParty() || $offer->offered_by_participant_id === $party->id) {
            abort(403, 'You cannot respond to this offer.');
        }

        DB::transaction(function () use ($dispute, $party, $offer, $accept) {
            /** @var OdrOffer $locked */
            $locked = OdrOffer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['offer' => 'This offer is no longer open.']);
            }
            $locked->forceFill(['status' => $accept ? 'accepted' : 'rejected', 'responded_at' => now()])->save();
            $this->post($dispute, $party, null, $accept ? 'Offer accepted.' : 'Offer rejected.', null, $accept ? 'acceptance' : 'rejection');
            if ($accept) {
                $dispute->forceFill(['status' => 'settlement_drafting'])->save();
            }
        });
    }

    public function proposeMediator(OdrDispute $dispute, MediatorProfile $mediator, User $staff): void
    {
        $this->assertStatus($dispute, ['negotiation', 'mediation', 'settlement_drafting']);
        if ($mediator->verification_status !== 'verified') {
            throw ValidationException::withMessages(['mediator_profile_id' => 'Only a verified mediator can be proposed.']);
        }
        if (OdrMediatorAssignment::query()->where('dispute_id', $dispute->id)->whereIn('status', ['proposed', 'accepted'])->exists()) {
            throw ValidationException::withMessages(['mediator_profile_id' => 'A mediator is already proposed or assigned.']);
        }
        if (OdrParticipant::query()->where(['dispute_id' => $dispute->id, 'user_id' => $mediator->user_id])->exists()) {
            throw ValidationException::withMessages(['mediator_profile_id' => 'This person is already part of the dispute.']);
        }

        $assignment = new OdrMediatorAssignment;
        $assignment->forceFill(['dispute_id' => $dispute->id, 'mediator_profile_id' => $mediator->id, 'assigned_by' => $staff->id, 'status' => 'proposed'])->save();
        app(AuditLogWriter::class)->record('odr.mediator_proposed', 'odr_dispute', $dispute->id);
    }

    /** A conflict disclosure is required, even if it's "None". */
    public function respondToAssignment(OdrMediatorAssignment $assignment, bool $accept, ?string $disclosure): void
    {
        if ($assignment->status !== 'proposed') {
            throw ValidationException::withMessages(['status' => 'This assignment has already been answered.']);
        }

        DB::transaction(function () use ($assignment, $accept, $disclosure) {
            $assignment->forceFill($accept
                ? ['status' => 'accepted', 'conflict_disclosure' => $disclosure, 'disclosed_at' => now(), 'accepted_at' => now()]
                : ['status' => 'declined', 'conflict_disclosure' => $disclosure, 'disclosed_at' => now()])->save();
            $dispute = $assignment->dispute;
            if ($accept && $dispute?->status === 'negotiation') {
                $dispute->forceFill(['status' => 'mediation'])->save();
            }
        });
    }

    public function withdrawAssignment(OdrMediatorAssignment $assignment): void
    {
        $assignment->forceFill(['status' => 'withdrawn'])->save();
    }

    /** @param array{scheduled_at: string, duration_minutes: int|string, mode: string} $data */
    public function scheduleSession(OdrDispute $dispute, array $data): OdrSession
    {
        $this->assertWritable($dispute);
        $session = new OdrSession($data);
        $session->forceFill(['dispute_id' => $dispute->id, 'status' => 'scheduled'])->save();

        return $session;
    }

    /**
     * Builds the unsigned agreement from a reviewed template. Any earlier version
     * that's still under review becomes void, so one set of terms is live at a time.
     */
    public function generateSettlement(OdrDispute $dispute, User $mediator, SettlementTemplate $template, string $terms): OdrSettlement
    {
        $this->assertWritable($dispute);
        if ($template->legal_review_status !== 'reviewed' || $template->category !== $dispute->category) {
            throw ValidationException::withMessages(['template_id' => 'Choose a legally reviewed template for this category.']);
        }
        $parties = OdrParticipant::query()->where(['dispute_id' => $dispute->id, 'status' => 'joined'])->whereIn('participant_role', ['claimant', 'respondent'])->get();
        if ($parties->count() < 2) {
            throw ValidationException::withMessages(['template_id' => 'Both parties must have joined first.']);
        }

        return DB::transaction(function () use ($dispute, $mediator, $template, $terms, $parties) {
            OdrDispute::query()->whereKey($dispute->id)->lockForUpdate()->first();
            OdrSettlement::query()->where('dispute_id', $dispute->id)->where('status', 'under_review')->update(['status' => 'void']);
            $version = (int) OdrSettlement::query()->where('dispute_id', $dispute->id)->max('version_number') + 1;

            $body = strtr($template->body_template, [
                '{dispute_number}' => (string) $dispute->dispute_number,
                '{claimant}' => (string) $parties->firstWhere('participant_role', 'claimant')?->name,
                '{respondent}' => (string) $parties->firstWhere('participant_role', 'respondent')?->name,
                '{terms}' => $terms,
            ]);
            $pdf = SimplePdf::render('Settlement agreement (unsigned)', [
                $template->title,
                'UNSIGNED. This is not a court order or decree. Nothing in it guarantees enforceability. Please get independent legal advice.',
                $body,
                'Version '.$version.', prepared '.now()->format('d M Y').'.',
            ]);

            $path = tempnam(sys_get_temp_dir(), 'pn-settlement');
            if ($path === false || file_put_contents($path, $pdf) === false) {
                throw new RuntimeException('Could not prepare the settlement file.');
            }
            try {
                $document = $this->documents->create($mediator, new UploadedFile($path, "settlement-{$dispute->dispute_number}-v{$version}.pdf", 'application/pdf', null, true), [
                    'title' => "Settlement agreement (unsigned) v{$version}", 'category' => 'settlement', 'confidentiality' => 'confidential',
                ], ['scope' => 'platform', 'odr_dispute_id' => $dispute->id]);
            } finally {
                @unlink($path);
            }
            DB::table('documents')->where('id', $document->id)->update(['source' => 'generated']);

            $settlement = new OdrSettlement;
            $settlement->forceFill([
                'dispute_id' => $dispute->id, 'version_number' => $version, 'template_id' => $template->id, 'terms' => ['text' => $terms],
                'document_id' => $document->id, 'content_hash' => hash('sha256', $pdf), 'status' => 'under_review', 'generated_by' => $mediator->id, 'generated_at' => now(),
            ])->save();

            foreach ($parties as $party) {
                $acceptance = new SettlementAcceptance;
                $acceptance->forceFill(['settlement_id' => $settlement->id, 'participant_id' => $party->id, 'method' => 'electronic_acceptance', 'status' => 'pending'])->save();
            }
            $dispute->forceFill(['status' => 'settlement_pending_acceptance'])->save();

            return $settlement;
        });
    }

    public function voidSettlement(OdrDispute $dispute, OdrSettlement $settlement): void
    {
        abort_unless($settlement->dispute_id === $dispute->id, 404);
        if ($settlement->status !== 'under_review') {
            throw ValidationException::withMessages(['status' => 'A settlement every party has accepted cannot be voided.']);
        }
        DB::transaction(function () use ($dispute, $settlement) {
            $settlement->forceFill(['status' => 'void'])->save();
            $dispute->forceFill(['status' => 'mediation'])->save();
        });
    }

    public function respondToSettlement(OdrDispute $dispute, OdrSettlement $settlement, OdrParticipant $party, bool $accept, ?string $ip): void
    {
        abort_unless($settlement->dispute_id === $dispute->id, 404);
        if ($settlement->status !== 'under_review') {
            throw ValidationException::withMessages(['status' => 'This settlement is no longer open.']);
        }

        DB::transaction(function () use ($dispute, $settlement, $party, $accept, $ip) {
            /** @var SettlementAcceptance|null $row */
            $row = SettlementAcceptance::query()->where(['settlement_id' => $settlement->id, 'participant_id' => $party->id])->lockForUpdate()->first();
            if ($row === null) {
                abort(403, 'You are not asked to accept this settlement.');
            }
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'You have already answered.']);
            }
            $row->forceFill(['status' => $accept ? 'accepted' : 'declined', 'ip' => $ip, 'acted_at' => now()])->save();

            if (! $accept) {
                $settlement->forceFill(['status' => 'void'])->save();
                $dispute->forceFill(['status' => 'mediation'])->save();

                return;
            }
            if (SettlementAcceptance::query()->where('settlement_id', $settlement->id)->where('status', '<>', 'accepted')->doesntExist()) {
                $settlement->forceFill(['status' => 'accepted_by_all'])->save();
                $dispute->forceFill(['status' => 'settled_unsigned', 'closed_at' => now(), 'closure_reason' => 'All parties accepted the terms. The agreement is unsigned.'])->save();
            }
        });
    }

    public function closeWithoutSettlement(OdrDispute $dispute, string $reason): void
    {
        $this->assertOpen($dispute);
        $dispute->forceFill(['status' => 'closed_no_settlement', 'closed_at' => now(), 'closure_reason' => $reason])->save();
    }

    private function invite(OdrDispute $dispute, string $role, string $name, string $email, ?string $organization): void
    {
        $token = Str::random(64);
        $participant = new OdrParticipant(['name' => $name, 'email' => mb_strtolower($email), 'organization_name' => $organization]);
        $participant->forceFill([
            'dispute_id' => $dispute->id, 'participant_role' => $role, 'status' => 'invited', 'invited_at' => now(),
            'invitation_token_hash' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(14),
        ])->save();

        Notification::route('mail', $email)->notify(new OdrInvitationNotification($token, (string) $dispute->dispute_number, $role));
    }

    /** @param list<string> $statuses */
    private function assertStatus(OdrDispute $dispute, array $statuses): void
    {
        if (! in_array($dispute->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'That step is not available for this dispute now.']);
        }
    }

    private function assertOpen(OdrDispute $dispute): void
    {
        if (! $dispute->isOpen() || $dispute->status === 'draft') {
            throw ValidationException::withMessages(['status' => 'This dispute is not open.']);
        }
    }

    /** B3-8: writing after closure is forbidden. Reading stays available. */
    private function assertWritable(OdrDispute $dispute): void
    {
        abort_unless($dispute->isOpen(), 403, 'This dispute is closed.');
    }
}
