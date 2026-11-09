<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A practice's own retainer template. Private to the practice, never public (BCI Rule 36). */
class RetainerPlan extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'monthly_fee', 'scope_items'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scope_items' => 'array', 'is_active' => 'boolean'];
    }
}
