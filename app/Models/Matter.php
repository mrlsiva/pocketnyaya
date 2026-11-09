<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<string, mixed> $metadata
 */
class Matter extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUSES_WRITABLE = ['unallocated', 'open', 'on_hold', 'disposed'];

    /** @var list<string> */
    protected $fillable = ['client_id', 'title', 'matter_type', 'practice_area_id', 'court_id', 'case_type_id', 'case_number', 'case_year', 'cnr_number', 'filing_date', 'stage', 'priority', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'metadata' => 'array',
            'filing_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isBillingRestricted(): bool
    {
        return ($this->metadata['billing_restricted'] ?? false) === true;
    }

    public function isWritable(): bool
    {
        return in_array($this->status, self::STATUSES_WRITABLE, true);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /** @return HasMany<MatterAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(MatterAssignment::class);
    }

    /** @return HasMany<MatterParty, $this> */
    public function parties(): HasMany
    {
        return $this->hasMany(MatterParty::class)->orderBy('sort_order');
    }

    /** @return HasMany<MatterTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(MatterTask::class);
    }

    /** @return HasMany<MatterNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(MatterNote::class);
    }

    /** @return HasMany<Hearing, $this> */
    public function hearings(): HasMany
    {
        return $this->hasMany(Hearing::class)->orderBy('hearing_date');
    }
}
