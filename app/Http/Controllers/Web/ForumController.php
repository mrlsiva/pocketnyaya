<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdvocateProfile;
use App\Models\ForumComment;
use App\Models\ForumQuestion;
use App\Models\User;
use App\Services\ForumService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** D8.11. Public pages show published posts only and never reveal who asked. */
class ForumController extends Controller
{
    public const REASONS = ['solicitation', 'laudatory', 'fee_quote', 'contact_info', 'abusive', 'misinformation', 'other'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('forum.index', [
            'questions' => ForumQuestion::query()->with('practiceArea')->withCount(['answers' => fn (Builder $q) => $q->where('status', 'published')])
                ->where('status', 'published')
                ->when($request->query('practice_area_id'), fn (Builder $q, $id) => $q->where('practice_area_id', $id))
                ->when($search !== '', function (Builder $q) use ($search) {
                    $like = '%'.addcslashes($search, '%_\\').'%';
                    $q->where(fn (Builder $s) => $s->where('title', 'like', $like)->orWhere('body', 'like', $like));
                })->latest('published_at')->paginate(20)->withQueryString(),
            'search' => $search,
            'areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'areaId' => (string) $request->query('practice_area_id'),
        ]);
    }

    public function show(ForumQuestion $question): View
    {
        abort_unless($question->status === 'published', 404);

        $answers = $question->answers()->with('advocate.user:id,name')->where('status', 'published')->orderBy('published_at')->get();
        $commentIds = $answers->pluck('id')->push($question->id);

        return view('forum.show', [
            'question' => $question,
            'answers' => $answers,
            'comments' => ForumComment::query()->whereIn('commentable_id', $commentIds)->where('status', 'published')->oldest()->get()->groupBy('commentable_id'),
            'reasons' => self::REASONS,
        ]);
    }

    public function create(): View
    {
        return view('forum.ask', ['areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request, ForumService $forum): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:10', 'max:200'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id'],
        ]);
        $question = $forum->ask($this->user($request), $data);

        return $question->status === 'published'
            ? redirect()->route('forum.show', $question)->with('status', 'Your question is published. Your name is not shown.')
            : redirect()->route('citizen.forum.mine')->with('status', 'Your question is waiting for a moderator to review it.');
    }

    public function mine(Request $request): View
    {
        return view('forum.mine', ['questions' => ForumQuestion::query()->where('author_user_id', $this->user($request)->id)->latest()->paginate(25)]);
    }

    public function answer(Request $request, ForumQuestion $question, ForumService $forum): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($question->status === 'published', 404);
        /** @var AdvocateProfile|null $profile */
        $profile = $user->advocateProfile;
        abort_unless($profile !== null && $profile->verification_status === 'verified', 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:20', 'max:10000']]);
        $answer = $forum->answer($question, $profile, $data['body']);

        return back()->with('status', $answer->status === 'published' ? 'Your answer is published.' : 'Your answer is waiting for a moderator to review it.');
    }

    public function comment(Request $request, ForumService $forum): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['forum_question', 'forum_answer'])],
            'id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $comment = $forum->comment($this->user($request), $data['type'], $data['id'], $data['body']);

        return back()->with('status', $comment->status === 'published' ? 'Comment added.' : 'Your comment is waiting for a moderator to review it.');
    }

    public function report(Request $request, ForumService $forum): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['forum_question', 'forum_answer', 'forum_comment'])],
            'id' => ['required', 'uuid'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $forum->report($this->user($request), $data['type'], $data['id'], $data['reason'], $data['details'] ?? null);

        return back()->with('status', 'Thank you. A moderator will review this.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
