<?php

namespace App\Models;

use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Append-only. The database rejects every update and delete.
 */
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * Storage locations and encryption metadata never appear in serialized output.
     *
     * @var list<string>
     */
    protected $hidden = ['storage_disk', 'storage_path', 'original_filename_enc', 'plaintext_mac', 'nonce_prefix'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasOne<DocumentKey, $this> */
    public function key(): HasOne
    {
        return $this->hasOne(DocumentKey::class);
    }

    /** No key row, or a destroyed one, means the file can't be decrypted. */
    public function isShredded(): bool
    {
        $key = $this->key;

        return $key === null || $key->destroyed_at !== null;
    }
}
