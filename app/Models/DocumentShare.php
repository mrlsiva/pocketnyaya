<?php

namespace App\Models;

use Database\Factories\DocumentShareFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentShare extends Model
{
    /** @use HasFactory<DocumentShareFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function granteeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    /**
     * Not revoked and not expired.
     *
     * @param  Builder<DocumentShare>  $query
     * @return Builder<DocumentShare>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
