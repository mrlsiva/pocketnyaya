<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Disclaimer extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'version', 'title', 'body', 'effective_from'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'effective_from' => 'date'];
    }

    public static function currentFor(string $code): ?self
    {
        return self::query()->where(['code' => $code, 'is_active' => true])->whereDate('effective_from', '<=', now()->toDateString())->orderByDesc('version')->first();
    }
}
