<?php

namespace App\Models;

use Database\Factories\BarCouncilFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarCouncil extends Model
{
    /** @use HasFactory<BarCouncilFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['state_id', 'code', 'name'];

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
