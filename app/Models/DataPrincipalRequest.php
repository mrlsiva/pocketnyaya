<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataPrincipalRequest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['request_type', 'details'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['identity_verified_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime', 'retained_items' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
