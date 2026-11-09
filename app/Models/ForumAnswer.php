<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForumAnswer extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<ForumQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ForumQuestion::class, 'question_id');
    }

    /** @return BelongsTo<AdvocateProfile, $this> */
    public function advocate(): BelongsTo
    {
        return $this->belongsTo(AdvocateProfile::class, 'advocate_profile_id');
    }
}
