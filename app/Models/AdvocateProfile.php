<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOwner;
use Database\Factories\AdvocateProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $enrollment_date
 */
class AdvocateProfile extends Model
{
    use BelongsToProfileOwner;

    /** @use HasFactory<AdvocateProfileFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;

    /**
     * Only the applicant's own details. Owner, verification and listing fields
     * are set by the workflows that control them, never by mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = ['bar_council_id', 'enrollment_number', 'enrollment_date', 'bio', 'city_id', 'office_address'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enrollment_date' => 'date',
            'verified_at' => 'datetime',
            'is_directory_listed' => 'boolean',
            'erased_at' => 'datetime',
        ];
    }

    /**
     * Years at the Bar is calculated, never stored or edited.
     *
     * @return Attribute<int<0, max>, never>
     */
    protected function yearsAtBar(): Attribute
    {
        return Attribute::get(fn (): int => max(0, (int) floor($this->enrollment_date->diffInYears(now()))));
    }

    /**
     * Public directory visibility: verified, listed and not suspended.
     *
     * @param  Builder<AdvocateProfile>  $query
     * @return Builder<AdvocateProfile>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified')->where('is_directory_listed', true);
    }

    /** @return BelongsTo<BarCouncil, $this> */
    public function barCouncil(): BelongsTo
    {
        return $this->belongsTo(BarCouncil::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasMany<AdvocateQualification, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(AdvocateQualification::class);
    }

    /** @return HasMany<VerificationRequest, $this> */
    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    /** @return BelongsToMany<PracticeArea, $this> */
    public function practiceAreas(): BelongsToMany
    {
        return $this->belongsToMany(PracticeArea::class, 'advocate_practice_areas')->withTimestamps();
    }

    /** @return BelongsToMany<Language, $this> */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'advocate_languages')->withTimestamps();
    }

    /** @return BelongsToMany<Court, $this> */
    public function courts(): BelongsToMany
    {
        return $this->belongsToMany(Court::class, 'advocate_courts')->withTimestamps();
    }
}
