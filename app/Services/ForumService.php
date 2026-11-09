<?php

namespace App\Services;

use App\Models\AdvocateProfile;
use App\Models\ContentReport;
use App\Models\ForumAnswer;
use App\Models\ForumComment;
use App\Models\ForumQuestion;
use App\Models\ModerationCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ForumService
{
    /** @var array<string, class-string<Model>> */
    private const TYPES = ['forum_question' => ForumQuestion::class, 'forum_answer' => ForumAnswer::class, 'forum_comment' => ForumComment::class];

    public function __construct(private ScreeningService $screening) {}

    /** @param array<string, mixed> $data */
    public function ask(User $user, array $data): ForumQuestion
    {
        $check = $this->screenOrReject($data['title'].' '.$data['body'], 'body');

        return DB::transaction(function () use ($user, $data, $check) {
            $question = new ForumQuestion($data);
            $question->forceFill(['author_user_id' => $user->id, 'is_anonymous' => true, 'public_alias' => 'Member-'.Str::upper(Str::random(8)), 'status' => 'pending_screening'])->save();
            $this->applyOutcome($question, 'forum_question', $data['title'].' '.$data['body'], $check);

            return $question->refresh();
        });
    }

    /**
     * D8.11 updateOwn: the author may edit a question only while it waits for review.
     * The edit is screened again, and any open review case for the old text is closed as edited.
     *
     * @param  array<string, mixed>  $data
     */
    public function editQuestion(ForumQuestion $question, User $user, array $data): ForumQuestion
    {
        if ($question->author_user_id !== $user->id || ! in_array($question->status, ['pending_screening', 'flagged'], true)) {
            throw ValidationException::withMessages(['body' => 'Only your own question can be edited, and only while it waits for review.']);
        }
        $text = $data['title'].' '.$data['body'];
        $check = $this->screenOrReject($text, 'body');

        return DB::transaction(function () use ($question, $data, $text, $check) {
            $question->fill($data);
            $question->forceFill(['status' => 'pending_screening'])->save();
            $this->closeOpenCases('forum_question', $question->id);
            $this->applyOutcome($question, 'forum_question', $text, $check);
            app(AuditLogWriter::class)->record('forum.question_edited', 'forum_question', $question->id);

            return $question->refresh();
        });
    }

    /** A verified advocate may edit their own answer. It is screened again before it shows. */
    public function editAnswer(ForumAnswer $answer, User $user, string $body): ForumAnswer
    {
        if ($answer->author_user_id !== $user->id || $answer->status === 'removed') {
            throw ValidationException::withMessages(['body' => 'Only your own answer can be edited.']);
        }
        $check = $this->screenOrReject($body, 'body');

        return DB::transaction(function () use ($answer, $body, $check) {
            $answer->forceFill(['body' => $body, 'status' => 'pending_screening'])->save();
            $this->closeOpenCases('forum_answer', $answer->id);
            $this->applyOutcome($answer, 'forum_answer', $body, $check);
            app(AuditLogWriter::class)->record('forum.answer_edited', 'forum_answer', $answer->id);

            return $answer->refresh();
        });
    }

    public function answer(ForumQuestion $question, AdvocateProfile $profile, string $body): ForumAnswer
    {
        if ($question->status !== 'published') {
            throw ValidationException::withMessages(['body' => 'This question is not open for answers.']);
        }
        $check = $this->screenOrReject($body, 'body');

        return DB::transaction(function () use ($question, $profile, $body, $check) {
            $answer = new ForumAnswer(['body' => $body]);
            $answer->forceFill(['question_id' => $question->id, 'advocate_profile_id' => $profile->id, 'author_user_id' => $profile->user_id, 'status' => 'pending_screening'])->save();
            $this->applyOutcome($answer, 'forum_answer', $body, $check);

            return $answer->refresh();
        });
    }

    public function comment(User $user, string $type, string $id, string $body): ForumComment
    {
        $target = $this->resolve($type, $id);
        if ($target === null || $target->getAttribute('status') !== 'published' || $type === 'forum_comment') {
            throw ValidationException::withMessages(['body' => 'This post is not open for comments.']);
        }
        $check = $this->screenOrReject($body, 'body');

        return DB::transaction(function () use ($user, $type, $id, $body, $check) {
            $comment = new ForumComment(['body' => $body]);
            $comment->forceFill(['commentable_type' => $type, 'commentable_id' => $id, 'author_user_id' => $user->id, 'status' => 'pending_screening'])->save();
            $this->applyOutcome($comment, 'forum_comment', $body, $check);

            return $comment->refresh();
        });
    }

    /** One report per person per post. Reporting opens a case for moderators. */
    public function report(User $user, string $type, string $id, string $reason, ?string $details): ContentReport
    {
        $target = $this->resolve($type, $id);
        if ($target === null || $target->getAttribute('status') !== 'published') {
            throw ValidationException::withMessages(['reason' => 'Only a published post can be reported.']);
        }
        if (DB::table('content_reports')->where(['reportable_type' => $type, 'reportable_id' => $id, 'reporter_user_id' => $user->id])->exists()) {
            throw ValidationException::withMessages(['reason' => 'You have already reported this post.']);
        }

        return DB::transaction(function () use ($user, $type, $id, $reason, $details) {
            $report = new ContentReport(['reason' => $reason, 'details' => $details]);
            $report->forceFill(['reportable_type' => $type, 'reportable_id' => $id, 'reporter_user_id' => $user->id, 'status' => 'open'])->save();
            $this->openCase($type, $id, 'Reported: '.$reason, null, $report->id);

            return $report;
        });
    }

    /**
     * Applies a moderator's decision to the post, and closes any linked report.
     * A rejected bio is cleared and stays off the public directory.
     */
    public function decide(ModerationCase $case, User $moderator, string $decision, ?string $note): void
    {
        if ($case->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'This case has already been decided.']);
        }

        DB::transaction(function () use ($case, $moderator, $decision, $note) {
            if ($decision !== 'escalated') {
                $approved = $decision === 'approved';
                if ($case->moderatable_type === 'advocate_profile') {
                    $profile = AdvocateProfile::query()->find($case->moderatable_id);
                    $profile?->forceFill($approved ? ['bio_screening_status' => 'passed'] : ['bio' => null, 'bio_screening_status' => 'rejected'])->save();
                } else {
                    $target = $this->resolve($case->moderatable_type, $case->moderatable_id);
                    if ($target !== null) {
                        $changes = ['status' => $approved ? 'published' : 'removed'];
                        if ($approved && in_array($case->moderatable_type, ['forum_question', 'forum_answer'], true) && $target->getAttribute('published_at') === null) {
                            $changes['published_at'] = now();
                        }
                        $target->forceFill($changes)->save();
                    }
                }
            }

            $case->forceFill(['status' => $decision, 'decided_by' => $moderator->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            if ($case->report_id !== null && $decision !== 'escalated') {
                DB::table('content_reports')->where('id', $case->report_id)->update([
                    'status' => $decision === 'approved' ? 'dismissed' : 'actioned', 'handled_by' => $moderator->id, 'handled_at' => now(), 'updated_at' => now(),
                ]);
            }
            app(AuditLogWriter::class)->record('moderation.decided', $case->moderatable_type, $case->moderatable_id, null, null, ['decision' => $decision]);
        });
    }

    /** The post's text for a moderator. Author identity is never included (D8.11). */
    public function textFor(ModerationCase $case): ?string
    {
        if ($case->moderatable_type === 'advocate_profile') {
            return AdvocateProfile::query()->whereKey($case->moderatable_id)->value('bio');
        }
        $target = $this->resolve($case->moderatable_type, $case->moderatable_id);
        if ($target === null) {
            return null;
        }

        return trim($target->getAttribute('title').' '.$target->getAttribute('body'));
    }

    public function openCase(string $type, string $id, string $reason, ?string $screeningId = null, ?string $reportId = null): void
    {
        $case = new ModerationCase;
        $case->forceFill(['moderatable_type' => $type, 'moderatable_id' => $id, 'screening_id' => $screeningId, 'report_id' => $reportId, 'reason' => Str::limit($reason, 195), 'status' => 'pending'])->save();
    }

    /**
     * @param  array{result: string, matched: list<array{category: string, severity: string}>}  $check
     */
    public function applyOutcome(ForumQuestion|ForumAnswer|ForumComment $post, string $type, string $text, array $check): void
    {
        $screeningId = $this->screening->record($type, (string) $post->getKey(), $text, $check);

        if ($check['result'] === 'flag') {
            $post->forceFill(['status' => 'flagged'])->save();
            $reason = $check['matched'] === [] ? 'No active screening rules' : implode(', ', array_unique(array_column($check['matched'], 'category')));
            $this->openCase($type, (string) $post->getKey(), 'Screening: '.$reason, $screeningId);

            return;
        }

        $changes = ['status' => 'published'];
        if (! $post instanceof ForumComment) {
            $changes['published_at'] = now();
        }
        $post->forceFill($changes)->save();
    }

    /** @return array{result: string, matched: list<array{category: string, severity: string}>} */
    private function screenOrReject(string $text, string $field): array
    {
        $check = $this->screening->check($text, 'forum');
        if ($check['result'] === 'block') {
            $categories = array_unique(array_column($check['matched'], 'category'));
            throw ValidationException::withMessages([$field => 'This can\'t be posted because it may break the rules on '.implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $categories)).'.']);
        }

        return $check;
    }

    /** Screening cases about text that no longer exists. Report cases stay open for moderators. */
    private function closeOpenCases(string $type, string $id): void
    {
        ModerationCase::query()->where(['moderatable_type' => $type, 'moderatable_id' => $id, 'status' => 'pending'])->whereNull('report_id')
            ->update(['status' => 'edited', 'decided_at' => now(), 'decision_note' => 'Edited by the author', 'updated_at' => now()]);
    }

    private function resolve(string $type, string $id): ?Model
    {
        $class = self::TYPES[$type] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }
}
