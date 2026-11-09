<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan limits (owner decision 2026-11-09). A practice with no current plan has no limits.
 * A limit applies only when its plan sets it; an empty value means unlimited. Super Admins
 * set the values per plan. Limits stop new additions only and never remove existing data.
 */
class SubscriptionLimits
{
    /** @var array<string, string> limit key => what it counts, for screens and messages */
    public const LIMITS = [
        'max_members' => 'members, including pending invitations',
        'max_active_matters' => 'open matters',
        'max_storage_mb' => 'MB of document storage',
    ];

    /** The current plan: trialing, active or past due, and within its dates. */
    public function currentPlan(string $tenantId): ?object
    {
        return DB::table('tenant_subscriptions as s')->join('subscription_plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.tenant_id', $tenantId)->whereIn('s.status', ['trialing', 'active', 'past_due'])
            ->where('s.starts_at', '<=', now())->where(fn ($q) => $q->whereNull('s.ends_at')->orWhere('s.ends_at', '>', now()))
            ->orderByDesc('s.starts_at')->first(['p.id', 'p.code', 'p.name', 'p.limits', 's.status', 's.ends_at']);
    }

    public function limit(string $tenantId, string $key): ?int
    {
        $plan = $this->currentPlan($tenantId);
        if ($plan === null) {
            return null;
        }
        $limits = json_decode((string) $plan->limits, true);
        $value = is_array($limits) ? ($limits[$key] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, array{used: int, limit: ?int}> */
    public function usage(string $tenantId): array
    {
        $usage = [];
        foreach (array_keys(self::LIMITS) as $key) {
            $usage[$key] = ['used' => $this->used($tenantId, $key), 'limit' => $this->limit($tenantId, $key)];
        }

        return $usage;
    }

    /** Refuses an addition that would go over the plan. $adding is people, matters or bytes. */
    public function ensureRoom(string $tenantId, string $key, int $adding = 1, string $field = 'plan'): void
    {
        $limit = $this->limit($tenantId, $key);
        if ($limit === null) {
            return;
        }
        $used = $this->used($tenantId, $key);
        $next = $key === 'max_storage_mb' ? (int) ceil(($this->storageBytes($tenantId) + $adding) / 1048576) : $used + $adding;
        if ($next > $limit) {
            throw ValidationException::withMessages([$field => "Your practice's plan allows {$limit} ".self::LIMITS[$key].'. Ask your Super Admin about a larger plan.']);
        }
    }

    private function used(string $tenantId, string $key): int
    {
        return match ($key) {
            'max_members' => DB::table('tenant_memberships')->where(['tenant_id' => $tenantId, 'status' => 'active'])->whereNull('deleted_at')->count()
                + DB::table('tenant_invitations')->where('tenant_id', $tenantId)->whereNotNull('pending_email')->where('expires_at', '>', now())->count(),
            'max_active_matters' => DB::table('matters')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotIn('status', ['closed', 'archived'])->count(),
            'max_storage_mb' => (int) ceil($this->storageBytes($tenantId) / 1048576),
            default => 0,
        };
    }

    private function storageBytes(string $tenantId): int
    {
        return (int) DB::table('document_versions as v')->join('documents as d', 'd.id', '=', 'v.document_id')
            ->where('d.tenant_id', $tenantId)->where('d.scope', 'tenant')->sum('v.size_bytes');
    }
}
