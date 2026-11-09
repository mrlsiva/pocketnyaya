<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataBreachIncident extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['detected_at', 'summary', 'severity', 'affected_principals', 'board_notified_at', 'principals_notified_at', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'board_notified_at' => 'datetime', 'principals_notified_at' => 'datetime'];
    }
}
