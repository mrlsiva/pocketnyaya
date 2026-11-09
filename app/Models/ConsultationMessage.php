<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'read_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
