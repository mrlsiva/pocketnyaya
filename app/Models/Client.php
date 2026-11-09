<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string|null $phone
 */
class Client extends Model
{
    use HasUuids, SoftDeletes;

    /** Fields the Finance Manager may change (DA-20). */
    public const BILLING_FIELDS = ['email', 'address', 'state_code', 'gstin', 'pan', 'is_business_entity', 'turnover_category'];

    /** @var list<string> */
    protected $fillable = ['client_type', 'name', 'email', 'phone', 'address', 'state_code', 'gstin', 'pan', 'is_business_entity', 'turnover_category', 'notes'];

    /** @var list<string> */
    protected $hidden = ['phone_bidx'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'address' => 'encrypted',
            'pan' => 'encrypted',
            'notes' => 'encrypted',
            'is_business_entity' => 'boolean',
        ];
    }

    /**
     * Encrypted at rest, with a blind index so an exact number can still be found.
     *
     * @return Attribute<string|null, array<string, string|null>>
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : decrypt($value),
            set: fn (?string $value) => [
                'phone' => $value === null ? null : encrypt($value),
                'phone_bidx' => $value === null ? null : self::phoneIndex($value),
            ],
        );
    }

    public static function phoneIndex(string $phone): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $phone) ?? '', 'phone-index|'.config('app.key'));
    }

    /** @return HasMany<Matter, $this> */
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
}
