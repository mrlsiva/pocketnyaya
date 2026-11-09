<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOwner;
use Database\Factories\MediatorProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediatorProfile extends Model
{
    use BelongsToProfileOwner;

    /** @use HasFactory<MediatorProfileFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;

    /**
     * Owner, verification and availability fields are set by their own workflows.
     *
     * @var list<string>
     */
    protected $fillable = ['accreditation_body', 'accreditation_number', 'accreditation_valid_until', 'fee_terms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accreditation_valid_until' => 'date',
            'is_available' => 'boolean',
            'erased_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Language, $this> */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'mediator_languages')->withTimestamps();
    }

    /** @return HasMany<MediatorCategory, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(MediatorCategory::class);
    }
}
