<?php

namespace App\Services;

use App\Models\Matter;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MatterService
{
    public function __construct(private NumberSequenceService $numbers) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Matter
    {
        $tenant = (string) AccessContext::currentTenantId();
        app(SubscriptionLimits::class)->ensureRoom($tenant, 'max_active_matters', 1, 'title');

        return DB::transaction(function () use ($user, $data, $tenant) {
            $year = now()->format('Y');
            $number = $this->numbers->next($tenant, 'matter', $year);

            $matter = new Matter($data);
            $matter->forceFill([
                'tenant_id' => $tenant,
                'matter_number' => sprintf('M-%s-%05d', $year, $number),
                'status' => 'unallocated',
                'opened_at' => now(),
                'created_by' => $user->id,
                'metadata' => [],
            ])->save();

            // A solo lawyer is the only person who can work on it, so it opens at once.
            if (DB::table('tenants')->where('id', $tenant)->value('type') === 'solo') {
                $this->assign($user, $matter, (string) MatterAccess::me($user), 'lead');
            }

            return $matter;
        });
    }

    public function assign(User $user, Matter $matter, string $membershipId, string $role): void
    {
        $active = DB::table('tenant_memberships')
            ->where(['id' => $membershipId, 'tenant_id' => $matter->tenant_id, 'status' => 'active'])
            ->whereNull('deleted_at')->exists();
        if (! $active) {
            throw ValidationException::withMessages(['membership_id' => 'That person is not an active member of this practice.']);
        }

        $already = DB::table('matter_assignments')->where(['matter_id' => $matter->id, 'membership_id' => $membershipId])->whereNull('unassigned_at')->exists();
        if ($already) {
            throw ValidationException::withMessages(['membership_id' => 'That person is already on this matter.']);
        }

        DB::transaction(function () use ($user, $matter, $membershipId, $role) {
            DB::table('matter_assignments')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id,
                'membership_id' => $membershipId, 'role_on_matter' => $role, 'assigned_by' => $user->id,
                'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($matter->status === 'unallocated') {
                $matter->forceFill(['status' => 'open'])->save();
            }
            $assignee = (string) DB::table('tenant_memberships')->where('id', $membershipId)->value('user_id');
            Realtime::toUser($assignee, 'matter.assigned', ['matter_id' => $matter->id, 'role' => $role]);
        });
    }

    public function close(Matter $matter): void
    {
        $matter->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    }

    public function reopen(Matter $matter): void
    {
        app(SubscriptionLimits::class)->ensureRoom($matter->tenant_id, 'max_active_matters', 1, 'status');
        $matter->forceFill(['status' => 'open', 'closed_at' => null])->save();
    }

    public function setBillingRestriction(Matter $matter, bool $restricted): void
    {
        $metadata = $matter->metadata ?? [];
        $metadata['billing_restricted'] = $restricted;
        $matter->forceFill(['metadata' => $metadata])->save();
    }
}
