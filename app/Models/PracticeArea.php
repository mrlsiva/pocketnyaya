<?php

namespace App\Models;

use Database\Factories\PracticeAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PracticeArea extends Model
{
    /** @use HasFactory<PracticeAreaFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['parent_id', 'slug', 'name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
