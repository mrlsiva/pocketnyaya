<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyResponder extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['organization_name', 'contact_phone', 'city_id', 'is_active', 'availability'];

    /** @var list<string> */
    protected $hidden = ['contact_phone'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contact_phone' => 'encrypted', 'is_active' => 'boolean', 'availability' => 'array'];
    }

    /** @return BelongsTo<AdvocateProfile, $this> */
    public function advocateProfile(): BelongsTo
    {
        return $this->belongsTo(AdvocateProfile::class);
    }
}
