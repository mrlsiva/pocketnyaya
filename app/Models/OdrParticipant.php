<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdrParticipant extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'organization_name'];

    protected $hidden = ['invitation_token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'invited_at' => 'datetime', 'joined_at' => 'datetime', 'invitation_expires_at' => 'datetime'];
    }

    public function isParty(): bool
    {
        return in_array($this->participant_role, ['claimant', 'respondent'], true);
    }

    /** @return BelongsTo<OdrDispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(OdrDispute::class, 'dispute_id');
    }
}
