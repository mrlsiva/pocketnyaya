<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expense_date
 */
class MatterExpense extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['matter_id', 'expense_date', 'category', 'amount', 'is_reimbursable', 'is_pure_agent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expense_date' => 'date', 'is_reimbursable' => 'boolean', 'is_pure_agent' => 'boolean'];
    }

    /** @return BelongsTo<Matter, $this> */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
