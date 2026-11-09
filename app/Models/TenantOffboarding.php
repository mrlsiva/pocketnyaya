<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Closing a practice. Never deletes data: final disposal is switched off
 * until retention rules are approved (owner decision 2026-11-09).
 *
 * @property Carbon|null $export_expires_at
 * @property Carbon|null $last_exported_at
 */
class TenantOffboarding extends Model
{
    use HasUuids;

    protected $table = 'tenant_offboarding';

    /** @var list<string> */
    protected $fillable = ['reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'approved_at' => 'datetime', 'suspended_at' => 'datetime', 'holds_checked_at' => 'datetime',
            'export_opened_at' => 'datetime', 'export_expires_at' => 'datetime', 'last_exported_at' => 'datetime',
            'retention_started_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'export_recipient_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'suspended', 'export_open'], true);
    }

    public function exportAvailable(): bool
    {
        return $this->status === 'export_open' && $this->export_expires_at !== null && $this->export_expires_at->isFuture();
    }
}
