<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleConflict extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }

    /** @return BelongsTo<Hearing, $this> */
    public function hearingA(): BelongsTo
    {
        return $this->belongsTo(Hearing::class, 'hearing_a_id');
    }

    /** @return BelongsTo<Hearing, $this> */
    public function hearingB(): BelongsTo
    {
        return $this->belongsTo(Hearing::class, 'hearing_b_id');
    }
}
