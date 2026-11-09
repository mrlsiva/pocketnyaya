<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ConsentPurpose;
use App\Models\DataPrincipalRequest;
use App\Models\Disclaimer;
use App\Models\User;
use App\Services\ConsentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** A person's own privacy controls (D8.12). */
class PrivacyController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('privacy.index', [
            'purposes' => ConsentPurpose::query()->current()->orderByDesc('is_mandatory')->orderBy('name')->get(),
            'states' => DB::table('user_consents')->where('user_id', $user->id)->pluck('status', 'consent_purpose_id'),
            'history' => DB::table('consent_records as r')->join('consent_purposes as p', 'p.id', '=', 'r.consent_purpose_id')
                ->where('r.user_id', $user->id)->orderByDesc('r.sequence_no')->limit(50)->get(['r.action', 'r.channel', 'r.created_at', 'p.name']),
            'requests' => DataPrincipalRequest::query()->where('user_id', $user->id)->latest()->get(),
        ]);
    }

    public function updateConsent(Request $request, ConsentPurpose $purpose, ConsentLedger $ledger): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['action' => ['required', Rule::in(['grant', 'withdraw'])]]);
        abort_unless($purpose->is_active, 404);

        $data['action'] === 'grant' ? $ledger->grant($user, $purpose) : $ledger->withdraw($user, $purpose);

        return back()->with('status', $data['action'] === 'grant' ? 'Consent recorded.' : 'Consent withdrawn.');
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['erasure', 'access', 'correction', 'grievance', 'nomination'])],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);
        $exists = DataPrincipalRequest::query()->where(['user_id' => $user->id, 'request_type' => $data['request_type']])
            ->whereIn('status', ['submitted', 'identity_verification', 'in_review', 'approved'])->exists();
        if ($exists) {
            return back()->withErrors(['request_type' => 'You already have an open request of this type.']);
        }

        $privacyRequest = new DataPrincipalRequest($data);
        $privacyRequest->forceFill(['user_id' => $user->id, 'status' => 'submitted', 'due_at' => now()->addDays((int) config('pocketnyaya.privacy_request_days'))])->save();

        return back()->with('status', 'Your request has been received.');
    }

    /** Withdraw only before processing starts (D8.12). */
    public function withdrawRequest(Request $request, DataPrincipalRequest $privacyRequest): RedirectResponse
    {
        abort_unless($privacyRequest->user_id === $this->user($request)->id, 404);
        abort_unless($privacyRequest->status === 'submitted', 422);
        $privacyRequest->forceFill(['status' => 'withdrawn'])->save();

        return back()->with('status', 'Request withdrawn.');
    }

    /** The person's own data as a file. Excludes secrets and other people's records. */
    public function export(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'account' => $user->only(['name', 'email', 'phone', 'locale', 'timezone', 'created_at']),
            'consents' => DB::table('consent_records as r')->join('consent_purposes as p', 'p.id', '=', 'r.consent_purpose_id')
                ->where('r.user_id', $user->id)->orderBy('r.sequence_no')->get(['p.code', 'p.version', 'r.action', 'r.channel', 'r.created_at']),
            'forum_questions' => DB::table('forum_questions')->where('author_user_id', $user->id)->whereNull('deleted_at')->get(['title', 'body', 'status', 'created_at']),
            'vault_documents' => DB::table('documents')->where(['scope' => 'personal', 'owner_user_id' => $user->id])->whereNull('deleted_at')->get(['title', 'category', 'created_at']),
            'requests' => DataPrincipalRequest::query()->where('user_id', $user->id)->get(['request_type', 'status', 'created_at', 'completed_at']),
        ])->header('Content-Disposition', 'attachment; filename="my-pocketnyaya-data.json"')->header('Cache-Control', 'private, no-store');
    }

    public function disclaimer(string $code): View
    {
        $disclaimer = Disclaimer::currentFor($code);
        abort_if($disclaimer === null, 404);

        return view('privacy.disclaimer', ['disclaimer' => $disclaimer]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
