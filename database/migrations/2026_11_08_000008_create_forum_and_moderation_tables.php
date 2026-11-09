<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No votes, likes or "best answer": answers are chronological, so advocates aren't ranked. */
return new class extends Migration
{
    private const STATUSES = "'pending_screening','published','flagged','hidden','removed','closed'";

    public function up(): void
    {
        Schema::create('forum_questions', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('author_user_id');
            $table->boolean('is_anonymous')->default(true);
            $table->string('public_alias', 40);
            $table->string('title', 200);
            $table->text('body');
            $table->foreignId('practice_area_id')->nullable();
            $table->foreignId('city_id')->nullable();
            $table->foreignId('language_id')->nullable();
            $table->string('status', 18)->default('pending_screening');
            $table->dateTime('published_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['status', 'published_at']);
            $table->foreign('author_user_id')->references('id')->on('users');
            $table->foreign('practice_area_id')->references('id')->on('practice_areas');
            $table->foreign('city_id')->references('id')->on('cities');
            $table->foreign('language_id')->references('id')->on('languages');
        });

        Schema::create('forum_answers', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->pnUuid('question_id');
            $table->pnUuid('advocate_profile_id');
            $table->pnUuid('author_user_id');
            $table->text('body');
            $table->string('status', 18)->default('pending_screening');
            $table->dateTime('published_at', 6)->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['question_id', 'published_at']);
            $table->foreign('question_id')->references('id')->on('forum_questions');
            $table->foreign('advocate_profile_id')->references('id')->on('advocate_profiles');
            $table->foreign('author_user_id')->references('id')->on('users');
        });

        Schema::create('forum_comments', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('commentable_type', 20);
            $table->pnUuid('commentable_id');
            $table->pnUuid('author_user_id');
            $table->text('body');
            $table->string('status', 18)->default('pending_screening');
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->index(['commentable_type', 'commentable_id']);
            $table->foreign('author_user_id')->references('id')->on('users');
        });

        foreach (['forum_questions', 'forum_answers', 'forum_comments'] as $forum) {
            DB::statement("ALTER TABLE {$forum} ADD CONSTRAINT {$forum}_status_chk CHECK (status IN (".self::STATUSES.'))');
        }
        DB::statement("ALTER TABLE forum_comments ADD CONSTRAINT forum_comments_type_chk CHECK (commentable_type IN ('forum_question','forum_answer'))");

        Schema::create('content_reports', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('reportable_type', 20);
            $table->pnUuid('reportable_id');
            $table->pnUuid('reporter_user_id');
            $table->string('reason', 16);
            $table->text('details')->nullable();
            $table->string('status', 10)->default('open');
            $table->pnUuid('handled_by')->nullable();
            $table->dateTime('handled_at', 6)->nullable();
            $table->datetimes(6);

            $table->index(['status', 'created_at']);
            $table->unique(['reportable_type', 'reportable_id', 'reporter_user_id'], 'content_reports_once_uq');
            $table->foreign('reporter_user_id')->references('id')->on('users');
            $table->foreign('handled_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE content_reports ADD CONSTRAINT content_reports_reason_chk CHECK (reason IN ('solicitation','laudatory','fee_quote','contact_info','abusive','misinformation','other'))");
        DB::statement("ALTER TABLE content_reports ADD CONSTRAINT content_reports_status_chk CHECK (status IN ('open','actioned','dismissed'))");

        Schema::create('screening_rules', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('rule_type', 16);
            $table->string('category', 14);
            $table->string('pattern', 500);
            $table->string('language_code', 10)->nullable();
            $table->string('severity', 6);
            $table->string('applies_to', 8);
            $table->boolean('is_active')->default(true);
            $table->pnUuid('created_by')->nullable();
            $table->softDeletesDatetime('deleted_at', 6);
            $table->datetimes(6);

            $table->foreign('created_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE screening_rules ADD CONSTRAINT screening_type_chk CHECK (rule_type IN ('keyword','regex','contact_pattern'))");
        DB::statement("ALTER TABLE screening_rules ADD CONSTRAINT screening_category_chk CHECK (category IN ('laudatory','solicitation','fee_quote','contact_info','abusive','hostile_tone'))");
        DB::statement("ALTER TABLE screening_rules ADD CONSTRAINT screening_severity_chk CHECK (severity IN ('block','flag','warn'))");
        DB::statement("ALTER TABLE screening_rules ADD CONSTRAINT screening_applies_chk CHECK (applies_to IN ('profile','forum','odr','all'))");

        Schema::create('content_screenings', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('screenable_type', 30);
            $table->pnUuid('screenable_id');
            $table->char('content_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('engine', 6)->default('rules');
            $table->string('engine_version', 30);
            $table->string('result', 6);
            $table->json('matched')->nullable();
            $table->decimal('score', 5, 4)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();

            $table->index(['screenable_type', 'screenable_id']);
        });
        DB::statement("ALTER TABLE content_screenings ADD CONSTRAINT screenings_engine_chk CHECK (engine IN ('rules','ml'))");
        DB::statement("ALTER TABLE content_screenings ADD CONSTRAINT screenings_result_chk CHECK (result IN ('pass','warn','flag','block'))");
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared("CREATE TRIGGER content_screenings_ao_{$event} BEFORE {$event} ON content_screenings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_APPEND_ONLY: content_screenings'");
        }

        Schema::create('moderation_cases', function (Blueprint $table) {
            $table->pnUuid('id')->primary();
            $table->string('moderatable_type', 30);
            $table->pnUuid('moderatable_id');
            $table->pnUuid('screening_id')->nullable();
            $table->pnUuid('report_id')->nullable();
            $table->string('reason', 200);
            $table->string('status', 10)->default('pending');
            $table->pnUuid('assigned_to')->nullable();
            $table->pnUuid('decided_by')->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->text('decision_note')->nullable();
            $table->datetimes(6);

            $table->index(['status', 'created_at']);
            $table->foreign('screening_id')->references('id')->on('content_screenings');
            $table->foreign('report_id')->references('id')->on('content_reports');
            $table->foreign('assigned_to')->references('id')->on('users');
            $table->foreign('decided_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE moderation_cases ADD CONSTRAINT moderation_status_chk CHECK (status IN ('pending','approved','rejected','edited','escalated'))");
    }

    public function down(): void
    {
        foreach (['moderation_cases', 'content_screenings', 'screening_rules', 'content_reports', 'forum_comments', 'forum_answers', 'forum_questions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
