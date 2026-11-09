<?php

namespace App\Services;

use App\Models\Hearing;
use App\Models\Matter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HearingService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $attendees  membership ids
     */
    public function create(Matter $matter, array $data, array $attendees): Hearing
    {
        return DB::transaction(function () use ($matter, $data, $attendees) {
            $hearing = new Hearing($data);
            $hearing->forceFill(['tenant_id' => $matter->tenant_id, 'matter_id' => $matter->id])->save();

            foreach (array_unique($attendees) as $membership) {
                DB::table('hearing_attendees')->insertOrIgnore([
                    'hearing_id' => $hearing->id, 'membership_id' => $membership, 'tenant_id' => $matter->tenant_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->detectConflicts($hearing);

            return $hearing;
        });
    }

    /**
     * Records the outcome, and schedules the next hearing when a date is given.
     *
     * @param  array{status: string, outcome_summary?: string|null, next_hearing_date?: string|null}  $data
     */
    public function recordOutcome(Hearing $hearing, array $data): Hearing
    {
        return DB::transaction(function () use ($hearing, $data) {
            $next = null;
            if (! empty($data['next_hearing_date'])) {
                /** @var Matter $matter */
                $matter = $hearing->matter;
                $attendees = DB::table('hearing_attendees')->where('hearing_id', $hearing->id)->pluck('membership_id')->map(fn ($id) => (string) $id)->all();
                $next = $this->create($matter, [
                    'court_id' => $hearing->court_id, 'court_hall' => $hearing->court_hall, 'judge_name' => $hearing->judge_name,
                    'hearing_date' => $data['next_hearing_date'], 'purpose' => $hearing->purpose,
                ], $attendees);
            }

            $hearing->forceFill([
                'status' => $data['status'],
                'outcome_summary' => $data['outcome_summary'] ?? null,
                'next_hearing_date' => $data['next_hearing_date'] ?? null,
                'next_hearing_id' => $next?->id,
            ])->save();

            return $hearing;
        });
    }

    /** Flags attendees with two scheduled hearings on one day (firm-wide conflict alerts). */
    public function detectConflicts(Hearing $hearing): int
    {
        $others = DB::table('hearings as h')
            ->join('hearing_attendees as mine', 'mine.hearing_id', '=', DB::raw(DB::getPdo()->quote($hearing->id)))
            ->join('hearing_attendees as theirs', function ($join) {
                $join->on('theirs.hearing_id', '=', 'h.id')->on('theirs.membership_id', '=', 'mine.membership_id');
            })
            ->where('h.tenant_id', $hearing->tenant_id)
            ->where('h.id', '<>', $hearing->id)
            ->whereDate('h.hearing_date', $hearing->hearing_date)
            ->where('h.status', 'scheduled')
            ->whereNull('h.deleted_at')
            ->get(['h.id', 'h.court_id', 'h.scheduled_time', 'mine.membership_id']);

        $created = 0;
        foreach ($others as $other) {
            $sameCourt = (int) $other->court_id === (int) $hearing->court_id;
            $sameTime = $other->scheduled_time !== null && $other->scheduled_time === $hearing->scheduled_time;
            if ($sameCourt && ! $sameTime) {
                continue;
            }

            [$a, $b] = strcmp($hearing->id, (string) $other->id) < 0 ? [$hearing->id, $other->id] : [$other->id, $hearing->id];
            $created += DB::table('schedule_conflicts')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $hearing->tenant_id, 'membership_id' => $other->membership_id,
                'hearing_a_id' => $a, 'hearing_b_id' => $b,
                'conflict_type' => $sameCourt ? 'time_overlap' : 'different_court_same_day',
                'status' => 'open', 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $created;
    }
}
