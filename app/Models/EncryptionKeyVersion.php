<?php

namespace App\Models;

use Database\Factories\EncryptionKeyVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadata only. Key material is never stored in the database.
 */
class EncryptionKeyVersion extends Model
{
    /** @use HasFactory<EncryptionKeyVersionFactory> */
    use HasFactory;

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** @return HasMany<DocumentKey, $this> */
    public function documentKeys(): HasMany
    {
        return $this->hasMany(DocumentKey::class, 'kek_version_id');
    }
}
