<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SettlementTemplate extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['category', 'version', 'title', 'body_template'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
