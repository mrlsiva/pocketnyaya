<?php

namespace App\Models;

use Database\Factories\CourtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Court extends Model
{
    /** @use HasFactory<CourtFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['court_type', 'parent_court_id', 'name', 'ecourts_code', 'state_id', 'city_id', 'address', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
