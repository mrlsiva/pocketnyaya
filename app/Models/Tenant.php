<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Type, owner and status are set by the code that controls them.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'legal_name', 'gst_registration_type', 'gstin', 'state_code', 'address_line1', 'address_line2', 'city_id', 'pincode', 'email', 'phone'];

    /** @var list<string> */
    protected $hidden = ['pan'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pan' => 'encrypted',
            'settings' => 'array',
            'ml_training_opt_in' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<TenantMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }
}
