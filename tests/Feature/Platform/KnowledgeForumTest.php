<?php

namespace Tests\Feature\Platform;

use App\Models\AdvocateProfile;
use App\Models\ContentReport;
use App\Models\ForumAnswer;
use App\Models\ForumQuestion;
use App\Models\KnowledgeItem;
use App\Models\ModerationCase;
use App\Models\TaxRule;
use App\Models\User;
use App\Services\ForumService;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Database\Seeders\ScreeningRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Practice\PracticeTestCase;

class KnowledgeForumTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ScreeningRuleSeeder::class);
    }

    private function citizen(): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignCustomer($user);

        return $user;
    }

    private function advocate(): User
    {
        $user = $this->citizen();
        $profile = AdvocateProfile::factory()->create(['user_id' => $user->id]);
        $profile->forceFill(['verification_status' => 'verified'])->save();
        app(RoleAssignmentService::class)->assign($user, 'Verified Advocate', (string) AccessContext::platformTenantId());

        return $user;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $platform = (string) AccessContext::platformTenantId();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $platform, 'user_id' => $user->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($user, $role, $platform);
        $this->actingAs($user)->withSession(['mfa_passed_for' => $user->id]);

        return $user;
    }

    private function question(?User $author = null, string $title = 'A question about my tenancy deposit', string $body = 'My landlord has not returned my deposit after I moved out.'): ForumQuestion
    {
        return app(ForumService::class)->ask($author ?? $this->citizen(), ['title' => $title, 'body' => $body]);
    }

    // Tax rule overlap (MY-7). Concurrency tests TX-C1 to TX-C9 are not covered here.

    private function taxRule(array $overrides): TaxRule
    {
        $rule = new TaxRule($overrides + ['code' => 'OVERLAP', 'name' => 'X', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'any', 'charge_mechanism' => 'nil', 'cgst_rate' => 0, 'sgst_rate' => 0, 'igst_rate' => 0, 'invoice_type' => 'bill_of_supply', 'effective_from' => '2027-04-01']);
        $rule->save();

        return $rule;
    }

    public function test_tax_rules_cannot_overlap_but_adjacent_periods_can(): void
    {
        $this->taxRule(['effective_from' => '2027-04-01', 'effective_to' => '2028-03-31']);
        $this->taxRule(['effective_from' => '2028-04-01', 'effective_to' => '2029-03-31']);

        try {
            $this->taxRule(['effective_from' => '2027-10-01']);
            $this->fail('Overlap should be rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PN_TAX_OVERLAP', $e->getMessage());
        }
    }

    public function test_tax_overlap_rules_for_updates_scopes_and_deleted_rows(): void
    {
        $first = $this->taxRule(['effective_from' => '2027-04-01', 'effective_to' => '2028-03-31']);
        $second = $this->taxRule(['effective_from' => '2028-04-01']);

        try {
            $first->forceFill(['effective_to' => '2028-06-01'])->save();
            $this->fail('Extending into the next period should be rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PN_TAX_OVERLAP', $e->getMessage());
        }

        $override = new TaxRule(['code' => 'OVERLAP', 'name' => 'Firm', 'supplier_registration' => 'any', 'recipient_type' => 'any', 'recipient_turnover' => 'any', 'supply_type' => 'any', 'charge_mechanism' => 'nil', 'cgst_rate' => 0, 'sgst_rate' => 0, 'igst_rate' => 0, 'invoice_type' => 'bill_of_supply', 'effective_from' => '2027-04-01']);
        $override->forceFill(['tenant_id' => $this->firm()])->save();
        $this->assertTrue($override->exists);

        $second->delete();
        $this->assertTrue($this->taxRule(['effective_from' => '2028-05-01'])->exists);
    }

    // Knowledge vault (D8.10)

    public function test_knowledge_draft_approve_archive_and_visibility(): void
    {
        $firm = $this->firm();
        $senior = $this->member($firm, 'Senior Associate');

        $this->inTenant($senior, $firm);
        $this->post('/practice/knowledge', ['item_type' => 'template', 'title' => 'Bail template', 'summary' => 'General form', 'tags' => 'bail, criminal'])->assertRedirect();
        $item = KnowledgeItem::query()->where('tenant_id', $firm)->firstOrFail();
        $this->assertSame('draft', $item->status);
        $this->assertSame(['bail', 'criminal'], $item->tags);

        $this->get('/practice/knowledge')->assertOk()->assertSee('Bail template');
        $this->post("/practice/knowledge/{$item->id}/approve")->assertForbidden();

        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->post("/practice/knowledge/{$item->id}/approve")->assertRedirect();
        $this->assertSame('approved', $item->fresh()->status);
        $this->get('/practice/knowledge?q=criminal')->assertOk()->assertSee('Bail template');
        $this->get('/practice/knowledge?q=nothing-matches')->assertOk()->assertDontSee('Bail template');
        $this->post("/practice/knowledge/{$item->id}/approve")->assertStatus(422);
        $this->post("/practice/knowledge/{$item->id}/archive")->assertRedirect();
        $this->assertSame('archived', $item->fresh()->status);
    }

    public function test_knowledge_is_closed_to_other_roles_and_firms(): void
    {
        $firm = $this->firm();
        $this->inTenant($this->member($firm, 'Managing Partner'), $firm);
        $this->post('/practice/knowledge', ['item_type' => 'opinion', 'title' => 'Private opinion'])->assertRedirect();
        $item = KnowledgeItem::query()->where('tenant_id', $firm)->firstOrFail();

        foreach (['Finance Manager', 'Firm Admin', 'Clerk / Office Staff'] as $role) {
            $this->inTenant($this->member($firm, $role), $firm);
            $this->get('/practice/knowledge')->assertForbidden();
        }

        $other = $this->firm();
        $this->inTenant($this->member($other, 'Managing Partner'), $other);
        $this->get('/practice/knowledge')->assertOk()->assertDontSee('Private opinion');
        $this->post("/practice/knowledge/{$item->id}/approve")->assertNotFound();
    }

    public function test_knowledge_source_matter_must_be_visible(): void
    {
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $hidden = $this->matter($firm);

        $this->inTenant($junior, $firm);
        $this->post('/practice/knowledge', ['item_type' => 'pleading', 'title' => 'From a matter', 'source_matter_id' => $hidden->id])->assertNotFound();
    }

    // Forum (D8.11)

    public function test_clean_question_publishes_and_hides_the_author(): void
    {
        $author = $this->citizen();
        $this->actingAs($author)->post('/citizen/forum', ['title' => 'A question about my tenancy deposit', 'body' => 'My landlord has not returned my deposit after I moved out.'])->assertRedirect();
        $question = ForumQuestion::query()->where('author_user_id', $author->id)->firstOrFail();
        $this->assertSame('published', $question->status);
        $this->assertArrayNotHasKey('author_user_id', $question->toArray());

        auth()->logout();
        $this->get('/forum')->assertOk()->assertSee('tenancy deposit');
        $this->get("/forum/{$question->id}")->assertOk()->assertSee($question->public_alias)->assertDontSee($author->name)->assertDontSee((string) $author->email);
    }

    public function test_contact_details_are_blocked_and_nothing_is_saved(): void
    {
        $author = $this->citizen();
        $this->actingAs($author);

        foreach (['Please ring me on 9876543210 about this', 'Please email someone@example.com about this', 'I can guarantee a 100% success rate here'] as $body) {
            $this->post('/citizen/forum', ['title' => 'A question about something legal', 'body' => $body])->assertSessionHasErrors('body');
        }
        $this->assertSame(0, ForumQuestion::query()->where('author_user_id', $author->id)->count());
    }

    public function test_flagged_question_waits_for_a_moderator_and_stays_private(): void
    {
        $question = $this->question(null, 'Who is the best lawyer for property?', 'I want to know how property partition works in general.');
        $this->assertSame('flagged', $question->status);
        $this->assertNull($question->published_at);
        $this->get("/forum/{$question->id}")->assertNotFound();
        $this->get('/forum')->assertOk()->assertDontSee('best lawyer');

        $case = ModerationCase::query()->where('moderatable_id', $question->id)->firstOrFail();
        $this->staff('Compliance Officer');
        $this->get('/staff/moderation')->assertOk()->assertSee('best lawyer');
        $this->post("/staff/moderation/{$case->id}/decide", ['decision' => 'approved'])->assertRedirect();

        $fresh = $question->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertNotNull($fresh->published_at);
        $this->post("/staff/moderation/{$case->id}/decide", ['decision' => 'rejected'])->assertSessionHasErrors('decision');
    }

    public function test_moderators_never_see_the_author(): void
    {
        $author = $this->citizen();
        $question = $this->question($author, 'Who is the best lawyer for property?', 'I want to know how property partition works.');
        $case = ModerationCase::query()->where('moderatable_id', $question->id)->firstOrFail();

        $this->staff('Compliance Officer');
        $this->get('/staff/moderation')->assertOk()->assertDontSee($author->name)->assertDontSee((string) $author->email);
        $this->post("/staff/moderation/{$case->id}/decide", ['decision' => 'rejected'])->assertRedirect();
        $this->assertSame('removed', $question->fresh()->status);
    }

    public function test_super_admin_cannot_decide_moderation(): void
    {
        $question = $this->question(null, 'Who is the best lawyer for property?', 'I want to know how property partition works.');
        $case = ModerationCase::query()->where('moderatable_id', $question->id)->firstOrFail();

        $this->staff('Super Admin');
        $this->get('/staff/moderation')->assertForbidden();
        $this->post("/staff/moderation/{$case->id}/decide", ['decision' => 'approved'])->assertForbidden();
    }

    public function test_only_verified_advocates_can_answer_and_only_published_questions(): void
    {
        $question = $this->question();
        $this->actingAs($this->citizen())->post("/citizen/forum/{$question->id}/answers", ['body' => 'This answer comes from someone without the role.'])->assertForbidden();

        $advocate = $this->advocate();
        $this->actingAs($advocate);
        $this->post("/citizen/forum/{$question->id}/answers", ['body' => 'A deposit generally must be returned under the rental agreement.'])->assertRedirect();
        $answer = ForumAnswer::query()->where('question_id', $question->id)->firstOrFail();
        $this->assertSame('published', $answer->status);

        $this->post("/citizen/forum/{$question->id}/answers", ['body' => 'Hire me today and I will sort this out for you.'])->assertSessionHasErrors('body');

        $question->forceFill(['status' => 'closed'])->save();
        $this->post("/citizen/forum/{$question->id}/answers", ['body' => 'Too late, as the question is closed now.'])->assertNotFound();
    }

    public function test_answers_are_chronological_and_there_is_no_ranking(): void
    {
        $question = $this->question();
        $service = app(ForumService::class);
        $firstAdvocate = AdvocateProfile::query()->where('user_id', $this->advocate()->id)->firstOrFail();
        $secondAdvocate = AdvocateProfile::query()->where('user_id', $this->advocate()->id)->firstOrFail();
        $service->answer($question, $firstAdvocate, 'First answer posted to this question.');
        $this->travel(1)->minutes();
        $service->answer($question, $secondAdvocate, 'Second answer posted to this question.');

        $this->get("/forum/{$question->id}")->assertOk()->assertSeeInOrder(['First answer posted', 'Second answer posted']);
        foreach (['votes', 'likes', 'rating', 'score', 'is_best'] as $column) {
            $this->assertFalse(Schema::hasColumn('forum_answers', $column));
        }
        $this->travelBack();
    }

    public function test_report_opens_a_case_once_per_person(): void
    {
        $question = $this->question();
        $reporter = $this->citizen();
        $this->actingAs($reporter);
        $form = ['type' => 'forum_question', 'id' => $question->id, 'reason' => 'abusive'];

        $this->post('/citizen/forum/reports', $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/citizen/forum/reports', $form)->assertSessionHasErrors('reason');
        $report = ContentReport::query()->where('reportable_id', $question->id)->firstOrFail();
        $case = ModerationCase::query()->where('report_id', $report->id)->firstOrFail();

        $this->staff('Compliance Officer');
        $this->post("/staff/moderation/{$case->id}/decide", ['decision' => 'rejected'])->assertRedirect();
        $this->assertSame('removed', $question->fresh()->status);
        $this->assertSame('actioned', $report->fresh()->status);

        $this->actingAs($this->citizen());
        $this->post('/citizen/forum/reports', $form)->assertSessionHasErrors('reason');
    }

    public function test_comment_rules(): void
    {
        $question = $this->question();
        $this->actingAs($this->citizen());
        $this->post('/citizen/forum/comments', ['type' => 'forum_question', 'id' => $question->id, 'body' => 'I had the same problem last year.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/citizen/forum/comments', ['type' => 'forum_question', 'id' => (string) Str::uuid(), 'body' => 'To nowhere.'])->assertSessionHasErrors('body');
        $this->post('/citizen/forum/comments', ['type' => 'users', 'id' => (string) Str::uuid(), 'body' => 'Bad type.'])->assertSessionHasErrors('type');
        $this->get("/forum/{$question->id}")->assertSee('same problem last year');
    }

    public function test_screening_records_a_hash_and_is_append_only(): void
    {
        $question = $this->question();
        $row = DB::table('content_screenings')->where('screenable_id', $question->id)->first();
        $this->assertSame(hash('sha256', $question->title.' '.$question->body), $row->content_hash);
        $this->assertStringNotContainsString('deposit', (string) $row->matched);

        $this->expectException(QueryException::class);
        DB::table('content_screenings')->where('id', $row->id)->update(['result' => 'block']);
    }

    public function test_rule_changes_apply_and_a_broken_pattern_is_refused(): void
    {
        $this->staff('Compliance Officer');
        $this->post('/staff/screening-rules', ['rule_type' => 'regex', 'category' => 'abusive', 'pattern' => '(unclosed', 'severity' => 'block', 'applies_to' => 'forum'])->assertSessionHasErrors('pattern');
        $this->post('/staff/screening-rules', ['rule_type' => 'keyword', 'category' => 'abusive', 'pattern' => 'forbiddenword', 'severity' => 'block', 'applies_to' => 'forum'])->assertRedirect();

        $this->actingAs($this->citizen());
        $this->post('/citizen/forum', ['title' => 'A question about something', 'body' => 'This contains the forbiddenword within the body.'])->assertSessionHasErrors('body');
    }

    public function test_bio_screening_blocks_flags_and_passes(): void
    {
        $user = $this->citizen();
        $this->actingAs($user);
        $city = (int) DB::table('cities')->insertGetId(['name' => 'City '.Str::random(5), 'state_id' => DB::table('states')->where('code', '33')->value('id')]);
        $council = (int) DB::table('bar_councils')->insertGetId(['code' => 'C-'.Str::random(8), 'name' => 'Council '.Str::random(5), 'state_id' => DB::table('states')->where('code', '33')->value('id')]);
        $form = ['city_id' => $city, 'bar_council_id' => $council, 'enrollment_number' => 'TN/'.Str::random(6), 'enrollment_date' => '2015-01-01'];

        $this->put('/citizen/advocate-profile', $form + ['bio' => 'Call 9876543210 today'])->assertSessionHasErrors('bio');
        $this->put('/citizen/advocate-profile', $form + ['bio' => 'I am the best lawyer here'])->assertRedirect();
        $profile = AdvocateProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('flagged', $profile->bio_screening_status);
        $this->assertTrue(ModerationCase::query()->where('moderatable_id', $profile->id)->exists());

        $this->actingAs($user->fresh());
        $this->put('/citizen/advocate-profile', $form + ['bio' => 'I practise civil law in Chennai.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('passed', $profile->fresh()->bio_screening_status);
    }
}
