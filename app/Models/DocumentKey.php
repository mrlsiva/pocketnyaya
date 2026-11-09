<?php

namespace App\Models;

use Database\Factories\DocumentKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service-only record with no user-facing access. Rows are tombstoned, never deleted.
 */
class DocumentKey extends Model
{
    /** @use HasFactory<DocumentKeyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'document_version_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['wrapped_dek'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rewrapped_at' => 'datetime',
            'destroyed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /** @return BelongsTo<EncryptionKeyVersion, $this> */
    public function keyVersion(): BelongsTo
    {
        return $this->belongsTo(EncryptionKeyVersion::class, 'kek_version_id');
    }
}
