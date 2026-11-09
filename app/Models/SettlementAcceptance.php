<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MVP: recorded consent to the terms. Explicitly not a digital signature. */
class SettlementAcceptance extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }
}
