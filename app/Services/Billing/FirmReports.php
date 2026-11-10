<?php

namespace App\Services\Billing;

use App\Models\Matter;
use App\Models\User;
use App\Support\BillingAccess;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated firm financials (PRD 2.4): billable hours and utilisation per member, and
 * fees billed and collected per lead advocate. Restricted matters the viewer can't see are
 * combined into one line (D4.1), so no matter detail leaks through the totals.
 */
class FirmReports
{
    /** @return array{0: Carbon, 1: Carbon} the Indian financial year containing $date */
    public static function financialYear(?Carbon $date = null): array
    {
        $date ??= now();
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return [Carbon::create($start, 4, 1)->startOfDay(), Carbon::create($start + 1, 3, 31)->endOfDay()];
    }

    /** @return list<object{name: string, total_minutes: int, billable_minutes: int, billed_minutes: int, utilisation: ?int}> */
    public function hoursByMember(string $tenantId, Carbon $from, Carbon $to): array
    {
        return DB::table('time_entries as t')->join('tenant_memberships as m', 'm.id', '=', 't.membership_id')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('t.tenant_id', $tenantId)->whereNull('t.deleted_at')->whereBetween('t.work_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('t.membership_id', 'u.name')->orderBy('u.name')
            // Column names go through the grammar, so the isolation table prefix applies to them too.
            ->select('u.name')
            ->selectRaw(sprintf("SUM(%s) total, SUM(CASE WHEN %s = 1 THEN %1\$s ELSE 0 END) billable, SUM(CASE WHEN %s = 'billed' THEN %1\$s ELSE 0 END) billed",
                DB::getQueryGrammar()->wrap('t.minutes'), DB::getQueryGrammar()->wrap('t.is_billable'), DB::getQueryGrammar()->wrap('t.status')))
            ->get()->map(fn ($r) => (object) [
                'name' => $r->name, 'total_minutes' => (int) $r->total, 'billable_minutes' => (int) $r->billable, 'billed_minutes' => (int) $r->billed,
                // Share of recorded time that is billable, as a whole percentage.
                'utilisation' => (int) $r->total > 0 ? intdiv((int) $r->billable * 100 + intdiv((int) $r->total, 2), (int) $r->total) : null,
            ])->all();
    }

    /** @return list<object{lead: string, fees: string, collected: string, invoices: int}> */
    public function revenueByLead(User $viewer, string $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('invoices as i')->where('i.tenant_id', $tenantId)->whereNull('i.deleted_at')->whereIn('i.status', ['issued', 'partially_paid', 'paid'])
            ->whereBetween('i.issue_date', [$from->toDateString(), $to->toDateString()])
            ->get(['i.id', 'i.matter_id', 'i.invoice_type', 'i.subtotal', 'i.amount_paid', 'i.total_amount']);
        $matters = Matter::query()->whereIn('id', $rows->pluck('matter_id')->filter()->unique())->get()->keyBy('id');
        $leads = DB::table('matter_assignments as a')->join('tenant_memberships as m', 'm.id', '=', 'a.membership_id')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('a.matter_id', $matters->keys())->where('a.role_on_matter', 'lead')->whereNull('a.unassigned_at')
            ->orderBy('a.assigned_at')->get(['a.matter_id', 'u.name'])->groupBy('matter_id');

        $totals = [];
        foreach ($rows as $row) {
            $matter = $row->matter_id !== null ? $matters->get($row->matter_id) : null;
            $lead = match (true) {
                $matter === null => 'No matter',
                ! BillingAccess::canSeeMatterBilling($viewer, $matter) => 'Restricted matters (combined)',
                default => $leads->get($matter->id)?->first()->name ?? 'No lead assigned',
            };
            $credit = $row->invoice_type === 'credit_note';
            $totals[$lead] ??= ['fees' => 0, 'collected' => 0, 'invoices' => 0];
            $totals[$lead]['fees'] += ($credit ? -1 : 1) * Money::toPaise($row->subtotal);
            // A credit note's allocation isn't money received, so it is taken back out of collections.
            $totals[$lead]['collected'] += $credit ? -Money::toPaise($row->total_amount) : Money::toPaise($row->amount_paid);
            $totals[$lead]['invoices'] += $credit ? 0 : 1;
        }
        ksort($totals);

        return array_map(fn ($lead, $t) => (object) ['lead' => $lead, 'fees' => Money::fromPaise($t['fees']), 'collected' => Money::fromPaise($t['collected']), 'invoices' => $t['invoices']], array_keys($totals), $totals);
    }
}
