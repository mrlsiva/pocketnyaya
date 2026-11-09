<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Notifications\PracticeExportReadyNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing a practice (owner decision 2026-11-09). Stages:
 *   1 request → 2 a different Super Admin approves and the practice is suspended at once →
 *   3 legal holds are checked → 4 the named firm recipient downloads the export (or it is waived) →
 *   5 retention: the practice is closed and hidden, its data kept → 6 final disposal stays
 *   switched off until retention rules are approved → 7 every step is an audit event.
 *
 * Nothing here deletes data, and nothing touches anyone's personal account or other firms.
 */
class OffboardingService
{
    public function __construct(private AuditLogWriter $audit) {}

    public function request(User $actor, Tenant $tenant, string $reason, User $recipient): TenantOffboarding
    {
        if ($tenant->type === 'platform' || $tenant->status === 'closed' || $tenant->trashed()) {
            throw ValidationException::withMessages(['reason' => 'This practice can\'t be closed.']);
        }
        if (! $this->eligibleRecipients($tenant)->contains('id', $recipient->id)) {
            throw ValidationException::withMessages(['recipient' => 'The export recipient must be the owner, a Managing Partner or the Independent Lawyer of this practice.']);
        }

        return DB::transaction(function () use ($actor, $tenant, $reason, $recipient) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->value('id');
            if (TenantOffboarding::query()->where('tenant_id', $tenant->id)->whereIn('status', ['requested', 'suspended', 'export_open'])->exists()) {
                throw ValidationException::withMessages(['reason' => 'A closure is already in progress for this practice.']);
            }
            $offboarding = new TenantOffboarding(['reason' => $reason]);
            $offboarding->forceFill([
                'tenant_id' => $tenant->id, 'status' => 'requested', 'requested_by' => $actor->id, 'requested_at' => now(),
                'export_recipient_user_id' => $recipient->id,
            ])->save();
            $this->audit->record('offboarding.requested', 'tenant', $tenant->id, null, null, ['offboarding_id' => $offboarding->id]);

            return $offboarding;
        });
    }

    /** Stage 2 and 3: a second Super Admin approves; access stops at once and holds are recorded. */
    public function approve(User $actor, TenantOffboarding $offboarding): void
    {
        DB::transaction(function () use ($actor, $offboarding) {
            $locked = $this->lock($offboarding, ['requested']);
            if ($locked->requested_by === $actor->id) {
                throw ValidationException::withMessages(['status' => 'A different Super Admin must approve this closure.']);
            }
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($locked->tenant_id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => 'suspended', 'approved_by' => $actor->id, 'approved_at' => now(), 'suspended_at' => now(),
                'previous_tenant_status' => $tenant->status, 'holds_checked_at' => now(), 'active_holds_at_check' => $this->activeHolds($tenant->id),
            ])->save();
            $tenant->forceFill(['status' => 'suspended'])->save();
            // Nobody new can join a practice that is closing.
            DB::table('tenant_invitations')->where('tenant_id', $tenant->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

            $this->audit->record('offboarding.approved', 'tenant', $tenant->id, null, ['status' => $locked->previous_tenant_status], ['status' => 'suspended', 'active_holds' => $locked->active_holds_at_check]);
        });
    }

    /** Stage 4: the named recipient may download the export until it expires. */
    public function openExport(User $actor, TenantOffboarding $offboarding): void
    {
        DB::transaction(function () use ($offboarding) {
            $locked = $this->lock($offboarding, ['suspended', 'export_open']);
            $expires = now()->addDays((int) config('pocketnyaya.offboarding_export_days', 30));
            $locked->forceFill(['status' => 'export_open', 'export_opened_at' => $locked->export_opened_at ?? now(), 'export_expires_at' => $expires])->save();
            $this->audit->record('offboarding.export_opened', 'tenant', $locked->tenant_id, null, null, ['expires_at' => $expires->toIso8601String()]);

            $recipient = User::query()->find($locked->export_recipient_user_id);
            $recipient?->notify(new PracticeExportReadyNotification((string) Tenant::withTrashed()->whereKey($locked->tenant_id)->value('name'), $expires));
        });
    }

    public function waiveExport(User $actor, TenantOffboarding $offboarding, string $reason): void
    {
        DB::transaction(function () use ($offboarding, $reason) {
            $locked = $this->lock($offboarding, ['suspended', 'export_open']);
            $locked->forceFill(['export_waived_reason' => $reason])->save();
            $this->audit->record('offboarding.export_waived', 'tenant', $locked->tenant_id, null, null, ['reason' => $reason]);
        });
    }

    public function recordDownload(TenantOffboarding $offboarding): void
    {
        DB::transaction(function () use ($offboarding) {
            $locked = $this->lock($offboarding, ['export_open']);
            $locked->forceFill(['export_downloads' => $locked->export_downloads + 1, 'last_exported_at' => now()])->save();
            $this->audit->record('offboarding.exported', 'tenant', $locked->tenant_id);
        });
    }

    /**
     * Stage 5: retention. The practice is closed and hidden, memberships in it end (which removes
     * only this firm's roles), and its shares are revoked. Every record stays. Final disposal is
     * recorded as blocked, because no approved retention rule covers a closed practice yet.
     */
    public function startRetention(User $actor, TenantOffboarding $offboarding): void
    {
        DB::transaction(function () use ($actor, $offboarding) {
            $locked = $this->lock($offboarding, ['suspended', 'export_open']);
            if ($locked->last_exported_at === null && $locked->export_waived_reason === null) {
                throw ValidationException::withMessages(['status' => 'The firm must download its export first, or the export must be waived with a reason.']);
            }
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($locked->tenant_id)->lockForUpdate()->firstOrFail();
            $holds = $this->activeHolds($tenant->id);

            DB::table('tenant_memberships')->where('tenant_id', $tenant->id)->whereIn('status', ['invited', 'active', 'suspended'])
                ->update(['status' => 'left', 'left_at' => now(), 'updated_at' => now()]);
            DB::table('document_shares')->where('grantee_tenant_id', $tenant->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_subscriptions')->where('tenant_id', $tenant->id)->whereIn('status', ['trialing', 'active', 'past_due'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            $tenant->forceFill(['status' => 'closed', 'deleted_at' => now()])->save();

            $locked->forceFill([
                'status' => 'retention', 'retention_started_by' => $actor->id, 'retention_started_at' => now(),
                'holds_checked_at' => now(), 'active_holds_at_check' => $holds,
                'disposal_status' => $holds > 0 ? 'blocked_hold' : 'blocked_no_rule',
            ])->save();
            $this->audit->record('offboarding.retention_started', 'tenant', $tenant->id, null, ['status' => 'suspended'], ['status' => 'closed', 'active_holds' => $holds]);
        });
    }

    public function cancel(User $actor, TenantOffboarding $offboarding, string $reason): void
    {
        DB::transaction(function () use ($actor, $offboarding, $reason) {
            $locked = $this->lock($offboarding, ['requested', 'suspended', 'export_open']);
            if ($locked->status !== 'requested') {
                Tenant::query()->whereKey($locked->tenant_id)->update(['status' => $locked->previous_tenant_status ?? 'active', 'updated_at' => now()]);
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->audit->record('offboarding.cancelled', 'tenant', $locked->tenant_id, null, null, ['reason' => $reason]);
        });
    }

    /**
     * The people who may receive the firm's export: the owner, Managing Partners, or the Independent Lawyer.
     *
     * @return Collection<int, \stdClass>
     */
    public function eligibleRecipients(Tenant $tenant): Collection
    {
        $roleHolders = DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->where('r.team_id', $tenant->id)->whereIn('roles.name', ['Managing Partner', 'Independent Lawyer'])->pluck('r.model_id');

        return DB::table('users as u')->join('tenant_memberships as m', 'm.user_id', '=', 'u.id')
            ->where('m.tenant_id', $tenant->id)->where('m.status', 'active')->whereNull('m.deleted_at')->where('u.status', 'active')
            ->where(fn ($q) => $q->whereIn('u.id', $roleHolders)->orWhere('u.id', $tenant->owner_user_id))
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.email']);
    }

    /** Active holds on the practice, or on any of its matters or documents (C60 §4.2). */
    public function activeHolds(string $tenantId): int
    {
        return DB::table('legal_holds')->whereNull('released_at')->where(function ($q) use ($tenantId) {
            $q->where(fn ($w) => $w->where('holdable_type', 'tenant')->where('holdable_id', $tenantId))
                ->orWhere(fn ($w) => $w->where('holdable_type', 'matter')->whereIn('holdable_id', DB::table('matters')->where('tenant_id', $tenantId)->select('id')))
                ->orWhere(fn ($w) => $w->where('holdable_type', 'document')->whereIn('holdable_id', DB::table('documents')->where('tenant_id', $tenantId)->select('id')));
        })->count();
    }

    /** @param  list<string>  $statuses */
    private function lock(TenantOffboarding $offboarding, array $statuses): TenantOffboarding
    {
        /** @var TenantOffboarding $locked */
        $locked = TenantOffboarding::query()->whereKey($offboarding->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'This closure is not at a stage where that can be done.']);
        }

        return $locked;
    }
}
