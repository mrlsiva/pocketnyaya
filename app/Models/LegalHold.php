<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LegalHold extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['holdable_type', 'holdable_id', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public static function isActive(string $type, string $id): bool
    {
        return self::query()->where(['holdable_type' => $type, 'holdable_id' => $id])->whereNull('released_at')->exists();
    }
}
