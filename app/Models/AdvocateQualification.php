<?php

namespace App\Models;

use Database\Factories\AdvocateQualificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvocateQualification extends Model
{
    /** @use HasFactory<AdvocateQualificationFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['degree', 'institution', 'year_of_passing'];

    /** @return BelongsTo<AdvocateProfile, $this> */
    public function advocateProfile(): BelongsTo
    {
        return $this->belongsTo(AdvocateProfile::class);
    }
}
