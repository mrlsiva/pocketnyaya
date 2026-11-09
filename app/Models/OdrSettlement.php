<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OdrSettlement extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['terms' => 'array', 'generated_at' => 'datetime'];
    }

    /** @return HasMany<SettlementAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(SettlementAcceptance::class, 'settlement_id');
    }
}
