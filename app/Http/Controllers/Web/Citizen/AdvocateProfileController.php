<?php

namespace App\Http\Controllers\Web\Citizen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Document;
use App\Models\EmergencyResponder;
use App\Models\MediatorCategory;
use App\Models\MediatorProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentVault;
use App\Services\ForumService;
use App\Services\ScreeningService;
use App\Services\VerificationService;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdvocateProfileController extends Controller
{
    public function edit(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->advocateProfile()->with(['practiceAreas', 'languages', 'courts', 'qualifications'])->first();

        return view('citizen.advocate-profile', [
            'profile' => $profile,
            'mediator' => $user->mediatorProfile()->with(['languages', 'categories'])->first(),
            'requests' => VerificationRequest::query()->with('documents')
                ->where(fn ($q) => $q->where('advocate_profile_id', $profile->id ?? '')->orWhere('mediator_profile_id', $user->mediatorProfile->id ?? ''))
                ->latest('submitted_at')->get(),
            'councils' => DB::table('bar_councils')->orderBy('name')->get(['id', 'name']),
            'cities' => DB::table('cities')->orderBy('name')->get(['id', 'name']),
            'areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'languages' => DB::table('languages')->orderBy('name')->get(['id', 'name']),
            'courts' => DB::table('courts')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canList' => AccessContext::citizenCan($user, 'directory.listing.manage_own'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->advocateProfile;
        $verified = $profile?->verification_status === 'verified';

        $rules = [
            'bio' => ['nullable', 'string', 'max:5000'],
            'office_address' => ['nullable', 'string', 'max:500'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'practice_area_ids' => ['nullable', 'array'],
            'practice_area_ids.*' => ['integer', 'exists:practice_areas,id'],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', 'exists:languages,id'],
            'court_ids' => ['nullable', 'array'],
            'court_ids.*' => ['integer', 'exists:courts,id'],
        ];
        // D8.4: the enrollment number is locked once verified.
        if (! $verified) {
            $rules += [
                'bar_council_id' => ['required', 'integer', 'exists:bar_councils,id'],
                'enrollment_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/\-. ]*$/',
                    Rule::unique('advocate_profiles', 'enrollment_number')->where('bar_council_id', $request->input('bar_council_id'))->ignore($profile?->id)],
                'enrollment_date' => ['required', 'date', 'before_or_equal:today'],
            ];
        }
        $data = $request->validate($rules);

        // Rule 36: a bio that clearly solicits work is refused before it's saved.
        $bioCheck = app(ScreeningService::class)->check((string) ($data['bio'] ?? ''), 'profile');
        if ($bioCheck['result'] === 'block') {
            throw ValidationException::withMessages(['bio' => 'Your bio can\'t include contact details or claims that promote you.']);
        }

        $fields = Arr::only($data, ['bio', 'office_address', 'city_id', 'bar_council_id', 'enrollment_number', 'enrollment_date']);
        $profile === null ? $profile = $user->advocateProfile()->create($fields) : $profile->update($fields);

        // D8.4: an edited bio goes back to screening, so it isn't public until passed.
        if ($profile->wasChanged('bio') || $profile->wasRecentlyCreated) {
            $flagged = $bioCheck['result'] === 'flag';
            $profile->forceFill(['bio_screening_status' => ! $flagged ? 'passed' : ($bioCheck['matched'] === [] ? 'pending' : 'flagged')])->save();
            $screeningId = app(ScreeningService::class)->record('advocate_profile', $profile->id, (string) $profile->bio, $bioCheck);
            if ($flagged) {
                app(ForumService::class)->openCase('advocate_profile', $profile->id, 'Bio screening', $screeningId);
            }
        }

        $profile->practiceAreas()->sync($data['practice_area_ids'] ?? []);
        $profile->languages()->sync($data['language_ids'] ?? []);
        $profile->courts()->sync($data['court_ids'] ?? []);

        return back()->with('status', 'Your profile has been saved.');
    }

    public function submit(Request $request, VerificationService $verification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::citizenCan($user, 'verification.advocate.apply'), 403);
        $profile = $user->advocateProfile;
        abort_if($profile === null, 422, 'Complete your profile first.');

        $verification->submit($profile);

        return back()->with('status', 'Submitted for verification. Please add your supporting documents.');
    }

    public function applyMediator(Request $request, VerificationService $verification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::citizenCan($user, 'verification.mediator.apply'), 403);
        $data = $request->validate([
            'accreditation_body' => ['required', 'string', 'max:150'],
            'accreditation_number' => ['required', 'string', 'max:60', Rule::unique('mediator_profiles', 'accreditation_number')->where('accreditation_body', $request->input('accreditation_body'))],
            'accreditation_valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::in(MediatorCategory::CODES)],
        ]);
        abort_if(MediatorProfile::query()->where('user_id', $user->id)->exists(), 422, 'You already have a mediator profile.');

        $profile = $user->mediatorProfile()->create(Arr::except($data, ['categories']));
        foreach ($data['categories'] ?? [] as $category) {
            $profile->categories()->create(['category' => $category]);
        }
        $verification->submit($profile);

        return back()->with('status', 'Mediator application submitted. Please add your supporting documents.');
    }

    public function uploadDocument(UploadDocumentRequest $request, VerificationRequest $verificationRequest, DocumentService $documents): RedirectResponse
    {
        Gate::authorize('createVerification', [Document::class, $verificationRequest]);
        /** @var User $user */
        $user = $request->user();

        $documents->create($user, $request->file('file'), ['title' => $request->validated('title'), 'category' => 'verification'], [
            'scope' => 'platform', 'verification_request_id' => $verificationRequest->id,
        ]);
        if ($verificationRequest->status === 'info_requested') {
            $verificationRequest->forceFill(['status' => 'in_review'])->save();
        }

        return back()->with('status', 'Document uploaded and encrypted.');
    }

    public function downloadDocument(Request $request, Document $document, DocumentVault $vault): mixed
    {
        Gate::authorize('download', $document);
        /** @var User $user */
        $user = $request->user();

        return $vault->download($document, $document->latestVersion(), $user);
    }

    public function listing(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::citizenCan($user, 'directory.listing.manage_own'), 403);
        $profile = $user->advocateProfile;
        abort_unless($profile !== null && $profile->verification_status === 'verified', 422);

        $profile->forceFill(['is_directory_listed' => $request->boolean('listed')])->save();

        return back()->with('status', $request->boolean('listed') ? 'You are now listed in the directory.' : 'You have been removed from the directory.');
    }

    public function joinResponders(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AccessContext::citizenCan($user, 'emergency.respond'), 403);
        $profile = $user->advocateProfile;
        abort_if($profile === null, 422);
        $data = $request->validate(['contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'], 'active' => ['required', 'boolean']]);

        $responder = EmergencyResponder::query()->firstOrNew(['advocate_profile_id' => $profile->id]);
        $responder->forceFill(['advocate_profile_id' => $profile->id, 'contact_phone' => $data['contact_phone'], 'city_id' => $profile->city_id, 'is_active' => $data['active']])->save();

        return back()->with('status', 'Your emergency response availability has been updated.');
    }
}
