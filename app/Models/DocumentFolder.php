<?php

namespace App\Models;

use Database\Factories\DocumentFolderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentFolder extends Model
{
    /** @use HasFactory<DocumentFolderFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;

    /**
     * Scope, tenant and owner can't change (C27), so they are never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'parent_id'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<DocumentFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'parent_id');
    }

    /** @return HasMany<DocumentFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(DocumentFolder::class, 'parent_id');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }
}
