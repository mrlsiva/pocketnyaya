<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\ModerationCase;
use App\Models\ScreeningRule;
use App\Models\User;
use App\Services\ForumService;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Moderators see content and never who wrote it (D8.11, DA-25). */
class ModerationController extends Controller
{
    public function index(Request $request, ForumService $forum): View
    {
        $cases = ModerationCase::query()->where('status', $request->query('status', 'pending'))->oldest()->paginate(25)->withQueryString();

        return view('staff.moderation', [
            'cases' => $cases,
            'status' => (string) $request->query('status', 'pending'),
            'texts' => $cases->getCollection()->mapWithKeys(fn (ModerationCase $c) => [$c->id => $forum->textFor($c)]),
            'screenings' => DB::table('content_screenings')->whereIn('id', $cases->getCollection()->pluck('screening_id')->filter())->pluck('matched', 'id'),
            'canDecide' => AccessContext::staffCan($this->user($request), 'moderation.queue.decide'),
        ]);
    }

    public function decide(Request $request, ModerationCase $case, ForumService $forum): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'escalated'])],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $forum->decide($case, $this->user($request), $data['decision'], $data['decision_note'] ?? null);

        return back()->with('status', 'Decision recorded.');
    }

    public function rules(): View
    {
        return view('staff.screening-rules', ['rules' => ScreeningRule::query()->orderBy('category')->get()]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_type' => ['required', Rule::in(['keyword', 'regex', 'contact_pattern'])],
            'category' => ['required', Rule::in(['laudatory', 'solicitation', 'fee_quote', 'contact_info', 'abusive', 'hostile_tone'])],
            'pattern' => ['required', 'string', 'max:500'],
            'severity' => ['required', Rule::in(['block', 'flag', 'warn'])],
            'applies_to' => ['required', Rule::in(['profile', 'forum', 'odr', 'all'])],
        ]);
        if ($data['rule_type'] !== 'keyword' && @preg_match('~'.str_replace('~', '\~', $data['pattern']).'~iu', '') === false) {
            return back()->withErrors(['pattern' => 'That pattern is not valid.'])->withInput();
        }

        $rule = new ScreeningRule($data);
        $rule->forceFill(['created_by' => $this->user($request)->id, 'is_active' => true])->save();

        return back()->with('status', 'Rule added.');
    }

    public function toggleRule(ScreeningRule $rule): RedirectResponse
    {
        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        return back()->with('status', $rule->is_active ? 'Rule turned on.' : 'Rule turned off.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
