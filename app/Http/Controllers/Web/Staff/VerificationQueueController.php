<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Documents\DocumentVault;
use App\Services\VerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VerificationQueueController extends Controller
{
    public function index(): View
    {
        return view('staff.verification.index', [
            'requests' => VerificationRequest::query()->with(['advocateProfile.user:id,name', 'mediatorProfile.user:id,name'])
                ->whereIn('status', ['submitted', 'in_review', 'info_requested'])->orderBy('submitted_at')->paginate(25),
        ]);
    }

    public function show(VerificationRequest $verificationRequest): View
    {
        return view('staff.verification.show', [
            'request' => $verificationRequest->load(['advocateProfile.user:id,name,email', 'advocateProfile.barCouncil', 'mediatorProfile.user:id,name,email']),
            'documents' => in_array($verificationRequest->status, VerificationRequest::STAFF_VISIBLE_STATUSES, true) ? $verificationRequest->documents()->with('versions')->get() : collect(),
        ]);
    }

    public function start(Request $request, VerificationRequest $verificationRequest, VerificationService $verification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $verification->startReview($verificationRequest, $user);

        return back()->with('status', 'Review started.');
    }

    public function info(Request $request, VerificationRequest $verificationRequest, VerificationService $verification): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        /** @var User $user */
        $user = $request->user();
        $verification->requestInfo($verificationRequest, $user, $data['note']);

        return back()->with('status', 'The applicant has been asked for more information.');
    }

    public function decide(Request $request, VerificationRequest $verificationRequest, VerificationService $verification): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:2000']]);
        /** @var User $user */
        $user = $request->user();
        $verification->decide($verificationRequest, $user, $data['decision'] === 'approve', $data['note'] ?? null);

        return redirect()->route('staff.verification.index')->with('status', 'Decision recorded.');
    }

    public function download(Request $request, Document $document, DocumentVault $vault): mixed
    {
        Gate::authorize('download', $document);
        /** @var User $user */
        $user = $request->user();

        return $vault->download($document, $document->latestVersion(), $user);
    }
}
