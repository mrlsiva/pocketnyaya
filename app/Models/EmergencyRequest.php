<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['category', 'city_id', 'preferred_language_id', 'contact_phone', 'description'];

    /** @var list<string> */
    protected $hidden = ['contact_phone', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contact_phone' => 'encrypted',
            'description' => 'encrypted',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'sla_due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<EmergencyResponder, $this> */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(EmergencyResponder::class, 'assigned_responder_id');
    }
}
