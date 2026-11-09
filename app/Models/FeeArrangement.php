<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeeArrangement extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['client_id', 'matter_id', 'arrangement_type', 'hourly_rate', 'retainer_amount', 'retainer_plan_id', 'billing_day', 'starts_on', 'ends_on', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Matter, $this> */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /** @return BelongsTo<RetainerPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RetainerPlan::class, 'retainer_plan_id');
    }

    /** @return HasMany<FeeScheduleItem, $this> */
    public function scheduleItems(): HasMany
    {
        return $this->hasMany(FeeScheduleItem::class);
    }

    public function isRetainer(): bool
    {
        return in_array($this->arrangement_type, ['monthly_retainer', 'creator_retainer'], true);
    }
}
