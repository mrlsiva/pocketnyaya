<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['state_id', 'name', 'is_pilot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_pilot' => 'boolean'];
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
