<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OdrSession extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['scheduled_at', 'duration_minutes', 'mode'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'summary' => 'encrypted'];
    }
}
