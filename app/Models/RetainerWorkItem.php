<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Work tracked under a retainer, such as a brand deal review. */
class RetainerWorkItem extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['matter_id', 'work_type', 'title', 'due_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<FeeArrangement, $this> */
    public function arrangement(): BelongsTo
    {
        return $this->belongsTo(FeeArrangement::class, 'fee_arrangement_id');
    }

    /** @return BelongsTo<Matter, $this> */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
