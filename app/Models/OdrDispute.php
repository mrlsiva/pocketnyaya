<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OdrDispute extends Model
{
    use HasUuids, SoftDeletes;

    public const CATEGORIES = ['small_claims', 'landlord_tenant', 'ecommerce', 'service', 'creator_brand', 'other'];

    public const CLOSED = ['screening_failed', 'settled_unsigned', 'closed_no_settlement', 'withdrawn', 'expired'];

    /** @var list<string> */
    protected $fillable = ['category', 'title', 'description', 'claim_amount', 'city_id', 'language_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['description' => 'encrypted', 'negotiation_deadline' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED, true);
    }

    /** @return HasMany<OdrParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(OdrParticipant::class, 'dispute_id');
    }
}
