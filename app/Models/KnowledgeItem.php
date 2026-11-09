<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeItem extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['item_type', 'title', 'practice_area_id', 'court_id', 'tags', 'summary', 'source_matter_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tags' => 'array', 'approved_at' => 'datetime'];
    }
}
