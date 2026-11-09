<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\LegalHold;
use App\Models\RetentionRule;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Retention enforcement (owner decision 2026-11-09).
 *
 * 1. Nightly, report() lists what each approved rule makes due. Nothing changes.
 * 2. One person proposes a reported batch, and a different person approves it.
 * 3. On approval, each item is checked again (rule still approved, still due, no hold)
 *    before it is disposed of. The batch keeps the record: ids, counts and a hash, never content.
 *
 * Only these categories are supported. A rule for any other category does nothing,
 * so a typo in a draft can never dispose of the wrong data.
 */
class RetentionService
{
    /** @var array<string, array{trigger: string, action: string, label: string}> */
    public const CATEGORIES = [
        'closed_practice_documents' => ['trigger' => 'tenant_closure', 'action' => 'delete', 'label' => 'Documents of a closed practice, crypto-shredded'],
        'closed_practice_clients' => ['trigger' => 'tenant_closure', 'action' => 'anonymize', 'label' => 'Client personal details of a closed practice, anonymised'],
    ];

    public const ERASED_NAME = 'Erased client';

    public function __construct(private DocumentVault $vault, private AuditLogWriter $audit) {}

    /** @return int how many report batches were written */
    public function report(): int
    {
        $written = 0;
        foreach (self::CATEGORIES as $category => $spec) {
            $rule = $this->ruleFor($category);
            DB::transaction(function () use ($rule, $category, $spec, &$written) {
                DB::table('retention_batches')->where(['data_category' => $category, 'status' => 'report'])->update(['status' => 'superseded', 'updated_at' => now()]);
                if ($rule === null) {
                    return;
                }
                $items = $this->dueItems($category, $rule);
                if ($items === []) {
                    return;
                }
                DB::table('retention_batches')->insert([
                    'id' => (string) Str::uuid7(), 'retention_rule_id' => $rule->id, 'data_category' => $category, 'action' => $spec['action'],
                    'status' => 'report', 'items' => json_encode($items), 'item_count' => count($items), 'items_hash' => hash('sha256', implode(',', $items)),
                    'reported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $written++;
            });
        }

        return $written;
    }

    public function propose(string $batchId, User $user): void
    {
        DB::transaction(function () use ($batchId, $user) {
            $batch = $this->lock($batchId, 'report');
            DB::table('retention_batches')->where('id', $batch->id)->update(['status' => 'proposed', 'proposed_by' => $user->id, 'proposed_at' => now(), 'updated_at' => now()]);
            $this->audit->record('retention.batch_proposed', 'retention_batch', $batch->id, null, null, ['category' => $batch->data_category, 'items' => $batch->item_count]);
        });
    }

    public function cancel(string $batchId, User $user): void
    {
        DB::transaction(function () use ($batchId) {
            $batch = $this->lock($batchId, 'proposed');
            DB::table('retention_batches')->where('id', $batch->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->audit->record('retention.batch_cancelled', 'retention_batch', $batch->id);
        });
    }

    /** @return array{done: int, skipped_hold: int, skipped_not_due: int} */
    public function approveAndExecute(string $batchId, User $user): array
    {
        return DB::transaction(function () use ($batchId, $user) {
            $batch = $this->lock($batchId, 'proposed');
            if ($batch->proposed_by === $user->id) {
                throw ValidationException::withMessages(['batch' => 'A different person must approve this disposal.']);
            }
            $rule = $this->ruleFor($batch->data_category);
            if ($rule === null || $rule->id !== $batch->retention_rule_id) {
                throw ValidationException::withMessages(['batch' => 'The rule has changed since this batch was reported. Wait for the next report.']);
            }

            /** @var list<string> $items */
            $items = json_decode((string) $batch->items, true);
            $stillDue = array_flip($this->dueItems($batch->data_category, $rule, includeHeld: true));
            $summary = ['done' => 0, 'skipped_hold' => 0, 'skipped_not_due' => 0];
            $tenants = [];

            foreach ($items as $id) {
                if (! isset($stillDue[$id])) {
                    $summary['skipped_not_due']++;

                    continue;
                }
                $tenant = $batch->data_category === 'closed_practice_documents' ? $this->shredDocument($id) : $this->anonymiseClient($id);
                if ($tenant === null) {
                    $summary['skipped_hold']++;

                    continue;
                }
                $summary['done']++;
                $tenants[$tenant] = true;
            }

            DB::table('retention_batches')->where('id', $batch->id)->update([
                'status' => 'executed', 'approved_by' => $user->id, 'approved_at' => now(), 'executed_at' => now(), 'summary' => json_encode($summary), 'updated_at' => now(),
            ]);
            foreach (array_keys($tenants) as $tenant) {
                $this->updateClosureStatus($tenant);
            }
            $this->audit->record('retention.batch_executed', 'retention_batch', $batch->id, null, null, $summary + ['category' => $batch->data_category]);

            return $summary;
        });
    }

    /** The latest approved version of a supported rule, with a limit and a matching action. */
    public function ruleFor(string $category): ?RetentionRule
    {
        $spec = self::CATEGORIES[$category] ?? null;
        $rule = RetentionRule::query()->where(['data_category' => $category, 'status' => 'approved'])->orderByDesc('version')->first();
        if ($spec === null || $rule === null || $rule->retention_months === null || $rule->trigger_event !== $spec['trigger'] || $rule->on_expiry !== $spec['action']) {
            return null;
        }

        return $rule;
    }

    /** @return list<string> ids that are due and, unless asked, not under a hold */
    private function dueItems(string $category, RetentionRule $rule, bool $includeHeld = false): array
    {
        $tenants = DB::table('tenant_offboarding')->where('status', 'retention')
            ->where('retention_started_at', '<=', now()->subMonths((int) $rule->retention_months))->pluck('tenant_id')->all();
        if ($tenants === []) {
            return [];
        }

        if ($category === 'closed_practice_documents') {
            $ids = DB::table('documents as d')->whereIn('d.tenant_id', $tenants)->where('d.scope', 'tenant')
                ->whereExists(fn ($q) => $q->from('document_versions as v')->join('document_keys as k', 'k.document_version_id', '=', 'v.id')
                    ->whereColumn('v.document_id', 'd.id')->whereNull('k.destroyed_at'))
                ->orderBy('d.id')->pluck('d.id')->all();

            return array_values(array_filter($ids, fn ($id) => $includeHeld || ! LegalHold::coversDocument(Document::withTrashed()->findOrFail($id))));
        }

        $ids = DB::table('clients')->whereIn('tenant_id', $tenants)->where('name', '<>', self::ERASED_NAME)->orderBy('id')->pluck('id')->all();

        return array_values(array_filter($ids, fn ($id) => $includeHeld || ! LegalHold::coversClient(Client::withTrashed()->findOrFail($id))));
    }

    /** @return string|null the practice id, or null when a hold now covers it */
    private function shredDocument(string $id): ?string
    {
        $document = Document::withTrashed()->findOrFail($id);
        if (LegalHold::coversDocument($document)) {
            return null;
        }
        DocumentVersion::query()->where('document_id', $document->id)->get()->each(fn (DocumentVersion $v) => $this->vault->shred($v, 'retention_expiry'));
        if (! $document->trashed()) {
            $document->delete();
        }

        return $document->tenant_id;
    }

    private function anonymiseClient(string $id): ?string
    {
        $client = Client::withTrashed()->findOrFail($id);
        if (LegalHold::coversClient($client)) {
            return null;
        }
        $client->forceFill([
            'name' => self::ERASED_NAME, 'email' => null, 'phone' => null, 'phone_bidx' => null, 'address' => null,
            'pan' => null, 'gstin' => null, 'notes' => null, 'customer_user_id' => null,
        ])->save();

        return $client->tenant_id;
    }

    /** disposed once nothing supported is left for the practice; partly_disposed until then. */
    private function updateClosureStatus(string $tenantId): void
    {
        $documentsLeft = DB::table('documents as d')->where(['d.tenant_id' => $tenantId, 'd.scope' => 'tenant'])
            ->whereExists(fn ($q) => $q->from('document_versions as v')->join('document_keys as k', 'k.document_version_id', '=', 'v.id')->whereColumn('v.document_id', 'd.id')->whereNull('k.destroyed_at'))->exists();
        $clientsLeft = DB::table('clients')->where('tenant_id', $tenantId)->where('name', '<>', self::ERASED_NAME)->exists();

        DB::table('tenant_offboarding')->where(['tenant_id' => $tenantId, 'status' => 'retention'])
            ->update(['disposal_status' => $documentsLeft || $clientsLeft ? 'partly_disposed' : 'disposed', 'updated_at' => now()]);
    }

    private function lock(string $batchId, string $status): object
    {
        $batch = DB::table('retention_batches')->where('id', $batchId)->lockForUpdate()->first();
        if ($batch === null) {
            abort(404);
        }
        if ($batch->status !== $status) {
            throw ValidationException::withMessages(['batch' => 'This batch is not at a stage where that can be done.']);
        }

        return $batch;
    }
}
