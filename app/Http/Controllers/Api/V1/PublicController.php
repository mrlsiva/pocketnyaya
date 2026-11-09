<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdvocateProfile;
use App\Models\ConsultationMessage;
use App\Models\ConsultationRequest;
use App\Models\EmergencyRequest;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\DirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Directory, consultations and emergency requests for mobile apps. */
class PublicController extends Controller
{
    public function advocates(Request $request, DirectoryService $directory): JsonResponse
    {
        $page = $directory->search($request->only(['city_id', 'practice_area_id', 'language_id']))->paginate(20);

        return response()->json($page->through(fn (AdvocateProfile $p) => $directory->publicFields($p)));
    }

    public function advocate(string $advocate, DirectoryService $directory): JsonResponse
    {
        $profile = AdvocateProfile::query()->publiclyListed()->find($advocate);
        abort_if($profile === null, 404);

        return response()->json($directory->publicFields($profile));
    }

    public function consultations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = ConsultationRequest::query()->where('customer_user_id', $user->id)->latest()->paginate(25);

        return response()->json($page->through(fn (ConsultationRequest $c) => $this->consultation($c)));
    }

    public function requestConsultation(Request $request, ConsultationService $consultations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'advocate_id' => ['required', 'uuid'],
            'mode' => ['required', Rule::in(['chat', 'video', 'call', 'in_person'])],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
        ]);
        $profile = AdvocateProfile::query()->publiclyListed()->find($data['advocate_id']);
        abort_if($profile === null, 404);

        return response()->json($this->consultation($consultations->request($user, $profile, $data)), 201);
    }

    public function consultation_(Request $request, ConsultationRequest $consultation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($consultation->customer_user_id === $user->id, 404);

        return response()->json($this->consultation($consultation) + [
            'description' => $consultation->description,
            'messages' => $consultation->messages()->oldest()->get()->map(fn (ConsultationMessage $m) => ['id' => $m->id, 'body' => $m->body, 'mine' => $m->sender_user_id === $user->id, 'created_at' => $m->created_at]),
        ]);
    }

    public function message(Request $request, ConsultationRequest $consultation, ConsultationService $consultations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($consultation->customer_user_id === $user->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        return response()->json(['id' => $consultations->message($consultation, $user, $data['body'])->id], 201);
    }

    public function emergency(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'category' => ['required', Rule::in(['domestic_violence', 'harassment', 'protection_order', 'other'])],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'description' => ['required', 'string', 'max:5000'],
        ]);
        $priority = in_array($data['category'], ['domestic_violence', 'protection_order'], true) ? 'p1' : 'p2';

        $emergency = new EmergencyRequest($data);
        $emergency->forceFill(['customer_user_id' => $user->id, 'priority' => $priority, 'sla_due_at' => now()->addMinutes($priority === 'p1' ? 30 : 120)])->save();

        return response()->json(['id' => $emergency->id, 'status' => 'open', 'priority' => $priority], 201);
    }

    /** @return array<string, mixed> */
    private function consultation(ConsultationRequest $c): array
    {
        return $c->only(['id', 'advocate_profile_id', 'mode', 'subject', 'status', 'scheduled_at', 'created_at']);
    }
}
