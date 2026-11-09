<?php

namespace App\Http\Controllers\Web\Citizen;

use App\Http\Controllers\Controller;
use App\Models\AdvocateProfile;
use App\Models\User;
use App\Services\PracticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PracticeSetupController extends Controller
{
    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('citizen.practice-setup', [
            'profile' => $user->advocateProfile,
            'states' => DB::table('states')->orderBy('name')->get(['code', 'name']),
            'councils' => DB::table('bar_councils')->orderBy('name')->get(['id', 'name']),
            'cities' => DB::table('cities')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, PracticeService $practices): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $needsProfile = ! AdvocateProfile::query()->where('user_id', $user->id)->exists();

        $rules = [
            'type' => ['required', Rule::in(['solo', 'firm'])],
            'name' => ['required', 'string', 'max:200'],
            'state_code' => ['required', 'exists:states,code'],
        ];
        if ($needsProfile) {
            $rules += [
                'bar_council_id' => ['required', 'integer', 'exists:bar_councils,id'],
                'enrollment_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/\-. ]*$/',
                    Rule::unique('advocate_profiles', 'enrollment_number')->where('bar_council_id', $request->input('bar_council_id'))],
                'enrollment_date' => ['required', 'date', 'before_or_equal:today'],
                'city_id' => ['required', 'integer', 'exists:cities,id'],
            ];
        }
        $data = $request->validate($rules);

        $tenant = $practices->create($user, $data);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $tenant->id);

        return redirect()->route('practice.dashboard')->with('status', 'Your practice is ready.');
    }
}
