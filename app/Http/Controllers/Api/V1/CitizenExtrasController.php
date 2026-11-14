<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdvocateProfile;
use App\Models\ConsultationRequest;
use App\Models\DataPrincipalRequest;
use App\Models\EmergencyRequest;
use App\Models\EmergencyResponder;
use App\Models\ForumAnswer;
use App\Models\ForumQuestion;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Services\ForumService;
use App\Services\OffboardingService;
use App\Services\PracticeExportBuilder;
use App\Services\PracticeService;
use App\Support\AccessContext;
use App\Support\Isolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Personal-workspace actions the web screens have, for the app: opening a practice, the
 * forum, privacy, urgent help for responders, and a closing practice's export. Checks match
 * the web controllers.
 */
class CitizenExtrasController extends Controller
{
    /** Opens a solo or firm practice. An advocate profile is created first if there isn't one. */
    public function openPractice(Request $request, PracticeService $practices): JsonResponse
    {
        $user = $this->user($request);
        $rules = [
            'type' => ['required', Rule::in(['solo', 'firm'])],
            'name' => ['required', 'string', 'max:200'],
            'state_code' => ['required', 'exists:states,code'],
        ];
        if (! AdvocateProfile::query()->where('user_id', $user->id)->exists()) {
            $rules += [
                'bar_council_id' => ['required', 'integer', 'exists:bar_councils,id'],
                'enrollment_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/\-. ]*$/',
                    Rule::unique('advocate_profiles', 'enrollment_number')->where('bar_council_id', $request->input('bar_council_id'))],
                'enrollment_date' => ['required', 'date', 'before_or_equal:today'],
                'city_id' => ['required', 'integer', 'exists:cities,id'],
            ];
        }
        $tenant = $practices->create($user, $request->validate($rules));

        // The app sends this id as X-Tenant-ID from now on.
        return response()->json($tenant->only(['id', 'name', 'type', 'state_code']), 201);
    }

    public function cancelConsultation(Request $request, ConsultationRequest $consultation): JsonResponse
    {
        abort_unless($consultation->customer_user_id === $this->user($request)->id, 404);
        abort_unless(in_array($consultation->status, ['requested', 'accepted', 'scheduled'], true), 422, 'This consultation can no longer be cancelled.');
        $consultation->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['id' => $consultation->id, 'status' => 'cancelled']);
    }

    // Forum

    public function myQuestions(Request $request): JsonResponse
    {
        $page = ForumQuestion::query()->where('author_user_id', $this->user($request)->id)->latest()->paginate(25);

        return response()->json($page->through(fn (ForumQuestion $q) => $q->only(['id', 'title', 'body', 'status', 'practice_area_id', 'published_at', 'created_at'])));
    }

    /** Edits go through screening again, so a published question may go back to review. */
    public function updateQuestion(Request $request, ForumQuestion $question, ForumService $forum): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($question->author_user_id === $user->id, 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:10', 'max:200'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id'],
        ]);
        $question = $forum->editQuestion($question, $user, $data);

        return response()->json($question->only(['id', 'title', 'status']));
    }

    public function updateAnswer(Request $request, ForumAnswer $answer, ForumService $forum): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($answer->author_user_id === $user->id, 404);
        abort_unless($user->advocateProfile?->verification_status === 'verified', 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:20', 'max:10000']]);
        $answer = $forum->editAnswer($answer, $user, $data['body']);

        return response()->json($answer->only(['id', 'status']));
    }

    public function comment(Request $request, ForumService $forum): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['forum_question', 'forum_answer'])],
            'id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $comment = $forum->comment($this->user($request), $data['type'], $data['id'], $data['body']);

        return response()->json($comment->only(['id', 'status']), 201);
    }

    // Privacy

    /** The same machine-readable copy as the web download. */
    public function exportMyData(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'account' => $user->only(['name', 'email', 'phone', 'locale', 'timezone', 'created_at']),
            'consents' => DB::table('consent_records')->join('consent_purposes', 'consent_purposes.id', '=', 'consent_records.consent_purpose_id')
                ->where('consent_records.user_id', $user->id)->orderBy('consent_records.sequence_no')
                ->get(['consent_purposes.code', 'consent_purposes.version', 'consent_records.action', 'consent_records.channel', 'consent_records.created_at']),
            'forum_questions' => DB::table('forum_questions')->where('author_user_id', $user->id)->whereNull('deleted_at')->get(['title', 'body', 'status', 'created_at']),
            'vault_documents' => DB::table('documents')->where(['scope' => 'personal', 'owner_user_id' => $user->id])->whereNull('deleted_at')->get(['title', 'category', 'created_at']),
            'requests' => DataPrincipalRequest::query()->where('user_id', $user->id)->get(['request_type', 'status', 'created_at', 'completed_at']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function withdrawPrivacyRequest(Request $request, DataPrincipalRequest $privacyRequest): JsonResponse
    {
        abort_unless($privacyRequest->user_id === $this->user($request)->id, 404);
        abort_unless($privacyRequest->status === 'submitted', 422, 'Only a request that hasn\'t started can be withdrawn.');
        $privacyRequest->forceFill(['status' => 'withdrawn'])->save();

        return response()->json(['id' => $privacyRequest->id, 'status' => 'withdrawn']);
    }

    // Urgent help, for verified advocates

    public function assignedEmergencies(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::citizenCan($user, 'emergency.respond'), 403);

        return response()->json(['data' => EmergencyRequest::query()->with('city')
            ->whereIn('assigned_responder_id', EmergencyResponder::query()->whereIn('advocate_profile_id', $user->advocateProfile()->select('id'))->select('id'))
            ->whereIn('status', ['assigned', 'referred'])->latest()->get()
            ->map(fn (EmergencyRequest $e) => $e->only(['id', 'category', 'description', 'contact_phone', 'status', 'created_at']) + ['city' => $e->city?->name])]);
    }

    public function responderAvailability(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(AccessContext::citizenCan($user, 'emergency.respond'), 403);
        $profile = $user->advocateProfile;
        abort_if($profile === null, 422, 'Complete your advocate profile first.');
        $data = $request->validate(['contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'], 'active' => ['required', 'boolean']]);
        $responder = EmergencyResponder::query()->firstOrNew(['advocate_profile_id' => $profile->id]);
        $responder->forceFill(['advocate_profile_id' => $profile->id, 'contact_phone' => $data['contact_phone'], 'city_id' => $profile->city_id, 'is_active' => $data['active']])->save();

        return response()->json(['active' => (bool) $responder->is_active]);
    }

    // A closing practice's export, for its named recipient only

    public function practiceExports(Request $request): JsonResponse
    {
        return response()->json(['data' => TenantOffboarding::query()->with('tenant:id,name')->where('export_recipient_user_id', $this->user($request)->id)
            ->whereIn('status', ['export_open', 'retention'])->latest('requested_at')->get()
            ->map(fn (TenantOffboarding $o) => ['id' => $o->id, 'practice' => $o->tenant?->name, 'status' => $o->status, 'available' => $o->exportAvailable()])]);
    }

    public function downloadPracticeExport(Request $request, TenantOffboarding $offboarding, PracticeExportBuilder $builder, OffboardingService $service): BinaryFileResponse
    {
        $user = $this->user($request);
        abort_unless($offboarding->export_recipient_user_id === $user->id, 404);
        abort_unless($offboarding->exportAvailable(), 410, 'This export is no longer available.');
        if (Isolation::enabled()) {
            Isolation::usePlatform();
        }
        $path = $builder->build($offboarding, $user);
        $service->recordDownload($offboarding);

        return response()->download($path, 'practice-export-'.now()->format('Ymd').'.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
