<?php

namespace App\Services;

use App\Models\AdvocateProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Results are ordered by name only, so nothing ranks an advocate (BCI Rule 36).
 */
class DirectoryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AdvocateProfile>
     */
    public function search(array $filters): Builder
    {
        return AdvocateProfile::query()->publiclyListed()
            ->with(['user:id,name', 'city:id,name', 'barCouncil:id,name', 'practiceAreas:id,name', 'languages:id,name'])
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('city_id', $filters['city_id']))
            ->when(! empty($filters['practice_area_id']), fn ($q) => $q->whereHas('practiceAreas', fn ($s) => $s->where('practice_areas.id', $filters['practice_area_id'])))
            ->when(! empty($filters['language_id']), fn ($q) => $q->whereHas('languages', fn ($s) => $s->where('languages.id', $filters['language_id'])))
            ->join('users', 'users.id', '=', 'advocate_profiles.user_id')
            ->select('advocate_profiles.*')
            ->orderBy('users.name');
    }

    /**
     * The only fields the public may see (PRD 1.2).
     *
     * @return array<string, mixed>
     */
    public function publicFields(AdvocateProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->user?->name,
            'bar_council' => $profile->barCouncil?->name,
            'enrollment_number' => $profile->enrollment_number,
            'years_at_bar' => $profile->years_at_bar,
            'city' => $profile->city?->name,
            'practice_areas' => $profile->practiceAreas->pluck('name')->all(),
            'languages' => $profile->languages->pluck('name')->all(),
            'qualifications' => $profile->qualifications->map(fn ($q) => $q->only(['degree', 'institution', 'year_of_passing']))->all(),
            'bio' => $profile->bio_screening_status === 'passed' ? $profile->bio : null,
        ];
    }

    /** @return array<string, mixed> */
    public function filterOptions(): array
    {
        return [
            'cities' => DB::table('cities')->orderBy('name')->get(['id', 'name']),
            'areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'languages' => DB::table('languages')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
