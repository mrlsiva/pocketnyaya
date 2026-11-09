<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOwner;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerProfile extends Model
{
    use BelongsToProfileOwner;

    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['display_name', 'city_id', 'preferred_language_id', 'is_business', 'business_name', 'gstin', 'address'];

    /** @var list<string> */
    protected $hidden = ['address'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
            'address' => 'encrypted',
        ];
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function preferredLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'preferred_language_id');
    }
}
