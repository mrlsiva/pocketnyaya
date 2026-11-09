<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fixed fee for one kind of event, such as an appearance, under an arrangement. */
class FeeScheduleItem extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['event_type', 'description', 'amount'];

    /** @return BelongsTo<FeeArrangement, $this> */
    public function arrangement(): BelongsTo
    {
        return $this->belongsTo(FeeArrangement::class, 'fee_arrangement_id');
    }
}
