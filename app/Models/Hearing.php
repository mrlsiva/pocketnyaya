<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $hearing_date
 */
class Hearing extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['court_id', 'court_hall', 'judge_name', 'hearing_date', 'scheduled_time', 'item_number', 'purpose'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hearing_date' => 'date', 'next_hearing_date' => 'date'];
    }

    /** @return BelongsTo<Matter, $this> */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
