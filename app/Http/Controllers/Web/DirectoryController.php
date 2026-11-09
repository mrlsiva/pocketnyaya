<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdvocateProfile;
use App\Services\DirectoryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public and passive: objective filters and biographical facts only (BCI Rule 36). */
class DirectoryController extends Controller
{
    public function index(Request $request, DirectoryService $directory): View
    {
        return view('directory.index', $directory->filterOptions() + [
            'advocates' => $directory->search($request->only(['city_id', 'practice_area_id', 'language_id']))->paginate(20)->withQueryString(),
            'filters' => $request->only(['city_id', 'practice_area_id', 'language_id']),
        ]);
    }

    public function show(string $advocate, DirectoryService $directory): View
    {
        $profile = AdvocateProfile::query()->publiclyListed()->find($advocate);
        abort_if($profile === null, 404);

        return view('directory.show', ['advocate' => $directory->publicFields($profile), 'profileId' => $profile->id]);
    }
}
