<?php

namespace App\Support;

use App\Models\Document;
use App\Models\OdrDispute;
use App\Models\OdrMessage;
use App\Models\OdrParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * D5.5. Access comes only from a joined participant row (RC-PART) or an accepted
 * mediator assignment (RC-MED). No role opens a dispute, and that includes Super
 * Admin. Firm colleagues of a counsel participant get nothing.
 */
class OdrAccess
{
    public static function participant(User $user, OdrDispute $dispute): ?OdrParticipant
    {
        return OdrParticipant::query()->where(['dispute_id' => $dispute->id, 'user_id' => $user->id, 'status' => 'joined'])->first();
    }

    public static function mediatorProfileId(User $user, OdrDispute $dispute): ?string
    {
        $id = DB::table('odr_mediator_assignments as a')->join('mediator_profiles as m', 'm.id', '=', 'a.mediator_profile_id')
            ->where('a.dispute_id', $dispute->id)->where('m.user_id', $user->id)->where('a.status', 'accepted')->value('m.id');

        return is_string($id) ? $id : null;
    }

    public static function isMediator(User $user, OdrDispute $dispute): bool
    {
        return self::mediatorProfileId($user, $dispute) !== null && AccessContext::citizenCan($user, 'odr.mediate');
    }

    public static function canView(User $user, OdrDispute $dispute): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        return self::participant($user, $dispute) !== null || self::isMediator($user, $dispute);
    }

    /**
     * B3-4: a private message reaches only the mediator and that one participant.
     *
     * @param  Builder<OdrMessage>  $query
     * @return Builder<OdrMessage>
     */
    public static function visibleMessages(Builder $query, User $user, OdrDispute $dispute): Builder
    {
        $query->where('dispute_id', $dispute->id);
        if (self::isMediator($user, $dispute)) {
            return $query;
        }
        $me = self::participant($user, $dispute);

        return $query->where(fn (Builder $q) => $q->where('visibility', 'all_parties')->orWhere('private_with_participant_id', $me->id ?? ''));
    }

    /** Evidence and settlement files in a dispute. Caucus evidence follows its message. */
    public static function canSeeDocument(User $user, Document $document): bool
    {
        $dispute = $document->odr_dispute_id === null ? null : OdrDispute::query()->find($document->odr_dispute_id);
        if ($dispute === null || ! self::canView($user, $dispute)) {
            return false;
        }

        $private = OdrMessage::query()->where('document_id', $document->id)->where('visibility', 'mediator_private')->first();
        if ($private === null || self::isMediator($user, $dispute)) {
            return true;
        }

        return $private->private_with_participant_id === self::participant($user, $dispute)?->id;
    }
}
