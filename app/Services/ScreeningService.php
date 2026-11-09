<?php

namespace App\Services;

use App\Models\ScreeningRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rule-based screening shared by profile bios, forum posts and ODR messages. An ML
 * engine is a later phase, so this catches only what its configured rules describe.
 */
class ScreeningService
{
    public const ENGINE_VERSION = 'rules-1';

    /**
     * Checks text against active rules without recording anything.
     *
     * @return array{result: string, matched: list<array{category: string, severity: string}>}
     */
    public function check(string $text, string $appliesTo): array
    {
        $rules = ScreeningRule::query()->where('is_active', true)->whereIn('applies_to', [$appliesTo, 'all'])->get();
        if ($rules->isEmpty()) {
            // With no rules, nothing has really been screened, so a person must look at it.
            return ['result' => 'flag', 'matched' => []];
        }

        $matched = [];
        $rank = ['pass' => 0, 'warn' => 1, 'flag' => 2, 'block' => 3];
        $result = 'pass';

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $text)) {
                continue;
            }
            $matched[] = ['category' => $rule->category, 'severity' => $rule->severity];
            if ($rank[$rule->severity] > $rank[$result]) {
                $result = $rule->severity;
            }
        }

        return ['result' => $result, 'matched' => $matched];
    }

    /**
     * Records the outcome, keeping a hash and never the text.
     *
     * @param  array{result: string, matched: list<array{category: string, severity: string}>}  $check
     */
    public function record(string $type, string $id, string $text, array $check): string
    {
        $screeningId = (string) Str::uuid7();
        DB::table('content_screenings')->insert([
            'id' => $screeningId, 'screenable_type' => $type, 'screenable_id' => $id,
            'content_hash' => hash('sha256', $text), 'engine' => 'rules', 'engine_version' => self::ENGINE_VERSION,
            'result' => $check['result'], 'matched' => json_encode($check['matched']), 'created_at' => now(),
        ]);

        return $screeningId;
    }

    private function matches(ScreeningRule $rule, string $text): bool
    {
        if ($rule->rule_type === 'keyword') {
            return mb_stripos($text, $rule->pattern) !== false;
        }

        // Staff write these patterns, so a broken one must fail safe rather than crash a post.
        return @preg_match('~'.str_replace('~', '\~', $rule->pattern).'~iu', $text) === 1;
    }
}
