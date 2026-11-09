<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConsentPurpose extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'version', 'name', 'description', 'is_mandatory', 'effective_from'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'is_active' => 'boolean', 'effective_from' => 'date'];
    }

    /** @param Builder<ConsentPurpose> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_active', true)->whereDate('effective_from', '<=', now()->toDateString());
    }
}
