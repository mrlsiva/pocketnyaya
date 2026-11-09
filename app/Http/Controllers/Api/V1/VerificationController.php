<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Document;
use App\Models\MediatorCategory;
use App\Models\MediatorProfile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Documents\DocumentService;
use App\Services\VerificationService;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Advocate and mediator verification for mobile. Same rules as the web screens (D8.8). */
class VerificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $advocate = $user->advocateProfile;
        $mediator = MediatorProfile::query()->where('user_id', $user->id)->first();
        $requests = VerificationRequest::query()
            ->where(fn ($q) => $q->when($advocate !== null, fn ($w) => $w->orWhere('advocate_profile_id', $advocate?->id))
                ->when($mediator !== null, fn ($w) => $w->orWhere('mediator_profile_id', $mediator?->id)))
            ->when($advocate === null && $mediator === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('submitted_at')->get(['id', 'advocate_profile_id', 'mediator_profile_id', 'status', 'submitted_at']);

        return response()->json([
            'advocate' => $advocate === null ? null : ['verification_status' => $advocate->verification_status, 'is_directory_listed' => (bool) $advocate->is_directory_listed],
            'mediator' => $mediator === null ? null : ['verification_status' => $mediator->verification_status],
            'requests' => $requests->map(fn (VerificationRequest $r) => [
                'id' => $r->id, 'for' => $r->advocate_profile_id !== null ? 'advocate' : 'mediator', 'status' => $r->status, 'submitted_at' => $r->submitted_at,
                'documents' => Document::query()->where('verification_request_id', $r->id)->get(['id', 'title', 'created_at']),
            ]),
        ]);
    }

    /** The advocate profile itself is completed on the web form first. */
    public function submitAdvocate(Request $request, VerificationService $verification): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::citizenCan($user, 'verification.advocate.apply'), 403);
        $profile = $user->advocateProfile;
        abort_if($profile === null, 422, 'Complete your advocate profile first.');
        $verification->submit($profile);

        return response()->json(['message' => 'Submitted for verification. Please add your supporting documents.'], 201);
    }

    public function applyMediator(Request $request, VerificationService $verification): JsonResponse
    {
        $user = $this->user($request);
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

        return response()->json(['message' => 'Mediator application submitted. Please add your supporting documents.'], 201);
    }

    public function uploadDocument(UploadDocumentRequest $request, VerificationRequest $verificationRequest, DocumentService $documents): JsonResponse
    {
        Gate::authorize('createVerification', [Document::class, $verificationRequest]);
        $document = $documents->create($this->user($request), $request->file('file'), ['title' => $request->validated('title'), 'category' => 'verification'], [
            'scope' => 'platform', 'verification_request_id' => $verificationRequest->id,
        ]);
        if ($verificationRequest->status === 'info_requested') {
            $verificationRequest->forceFill(['status' => 'in_review'])->save();
        }

        return response()->json(['id' => $document->id], 201);
    }

    public function listing(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::citizenCan($user, 'directory.listing.manage_own'), 403);
        $profile = $user->advocateProfile;
        abort_unless($profile !== null && $profile->verification_status === 'verified', 422);
        $data = $request->validate(['listed' => ['required', 'boolean']]);
        $profile->forceFill(['is_directory_listed' => (bool) $data['listed']])->save();

        return response()->json(['is_directory_listed' => (bool) $data['listed']]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
