<?php

namespace App\Services;

use App\Models\AdvocateProfile;
use App\Models\MediatorProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verification of advocates and mediators (D8.4, D9). Approval grants the
 * Verified Advocate or Mediator role, and suspension or rejection removes it.
 */
class VerificationService
{
    public function __construct(private RoleAssignmentService $roles) {}

    public function submit(AdvocateProfile|MediatorProfile $profile): VerificationRequest
    {
        $column = $profile instanceof AdvocateProfile ? 'advocate_profile_id' : 'mediator_profile_id';

        return DB::transaction(function () use ($profile, $column) {
            // Serialises submissions, so one profile can't get two open requests.
            $profile->newQuery()->whereKey($profile->getKey())->lockForUpdate()->first();

            $open = VerificationRequest::query()->where($column, $profile->getKey())
                ->whereIn('status', ['submitted', 'in_review', 'info_requested'])->exists();
            if ($open) {
                throw ValidationException::withMessages(['request' => 'You already have an open verification request.']);
            }

            $request = new VerificationRequest(['submitted_at' => now(), 'checklist' => []]);
            $request->forceFill([$column => $profile->getKey(), 'status' => 'submitted'])->save();
            $profile->forceFill(['verification_status' => 'pending'])->save();

            return $request;
        });
    }

    public function startReview(VerificationRequest $request, User $officer): void
    {
        if ($request->status !== 'submitted') {
            throw ValidationException::withMessages(['request' => 'This request is not waiting for review.']);
        }
        $this->guardNotApplicant($request, $officer);
        $request->forceFill(['status' => 'in_review', 'assigned_to' => $officer->id])->save();
    }

    public function requestInfo(VerificationRequest $request, User $officer, string $note): void
    {
        $this->guardDecidable($request, $officer);
        $request->forceFill(['status' => 'info_requested', 'decision_notes' => $note])->save();
    }

    public function decide(VerificationRequest $request, User $officer, bool $approve, ?string $note): void
    {
        $this->guardDecidable($request, $officer);

        DB::transaction(function () use ($request, $officer, $approve, $note) {
            $request->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $officer->id,
                'reviewed_at' => now(),
                'decision_notes' => $note,
            ])->save();

            $profile = $request->advocateProfile ?? $request->mediatorProfile;
            /** @var User $applicant */
            $applicant = User::query()->findOrFail($profile?->user_id);
            $changes = ['verification_status' => $approve ? 'verified' : 'rejected'];
            if ($profile instanceof AdvocateProfile) {
                $changes += $approve ? ['verified_at' => now(), 'verified_by' => $officer->id] : ['is_directory_listed' => false];
            }
            $profile?->forceFill($changes)->save();

            $role = $profile instanceof AdvocateProfile ? 'Verified Advocate' : 'Mediator';
            $approve ? $this->grant($applicant, $role) : $this->remove($applicant, $role);
        });
    }

    public function suspendAdvocate(AdvocateProfile $profile): void
    {
        DB::transaction(function () use ($profile) {
            $profile->forceFill(['verification_status' => 'suspended', 'is_directory_listed' => false])->save();
            $this->remove(User::query()->findOrFail($profile->user_id), 'Verified Advocate');
        });
    }

    private function guardDecidable(VerificationRequest $request, User $officer): void
    {
        if ($request->status !== 'in_review') {
            throw ValidationException::withMessages(['request' => 'Only a request in review can be decided.']);
        }
        $this->guardNotApplicant($request, $officer);
    }

    private function guardNotApplicant(VerificationRequest $request, User $officer): void
    {
        if ($request->applicantUserId() === $officer->id) {
            abort(403, 'You cannot review your own verification request.');
        }
    }

    private function grant(User $user, string $role): void
    {
        $platform = (string) AccessContext::platformTenantId();
        if (! $this->holds($user, $role, $platform)) {
            $this->roles->assign($user, $role, $platform);
        }
    }

    private function remove(User $user, string $role): void
    {
        $platform = (string) AccessContext::platformTenantId();
        if ($this->holds($user, $role, $platform)) {
            $this->roles->revoke($user, $role, $platform);
        }
    }

    private function holds(User $user, string $role, string $tenant): bool
    {
        return DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->where(['r.team_id' => $tenant, 'r.model_id' => $user->id, 'roles.name' => $role])->exists();
    }
}
