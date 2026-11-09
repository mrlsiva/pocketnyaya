<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $created_at
 */
class MatterNote extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['body', 'visibility', 'is_pinned'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'is_pinned' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<Matter, $this> */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
