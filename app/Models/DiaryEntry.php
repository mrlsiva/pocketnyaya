<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiaryEntry extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['matter_id', 'entry_type', 'title', 'starts_at', 'ends_at', 'location'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
