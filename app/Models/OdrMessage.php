<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OdrMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'tone_check' => 'array', 'created_at' => 'datetime'];
    }
}
