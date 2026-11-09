<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForumQuestion extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['title', 'body', 'practice_area_id', 'city_id', 'language_id'];

    /** Never serialised: staff and the public must not see who asked. */
    protected $hidden = ['author_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'is_anonymous' => 'boolean'];
    }

    /** @return HasMany<ForumAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ForumAnswer::class, 'question_id');
    }

    /** @return BelongsTo<PracticeArea, $this> */
    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }
}
