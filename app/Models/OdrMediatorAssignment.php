<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdrMediatorAssignment extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['disclosed_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<OdrDispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(OdrDispute::class, 'dispute_id');
    }

    /** @return BelongsTo<MediatorProfile, $this> */
    public function mediator(): BelongsTo
    {
        return $this->belongsTo(MediatorProfile::class, 'mediator_profile_id');
    }
}
