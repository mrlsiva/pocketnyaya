<?php

namespace App\Http\Controllers\Web\Practice;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\SubscriptionLimits;
use App\Support\AccessContext;
use App\Support\MatterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Practice profile, branches, practice groups and availability. */
class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        $tenant = (string) AccessContext::currentTenantId();

        return view('practice.settings', [
            'tenant' => Tenant::query()->findOrFail($tenant),
            'branches' => DB::table('tenant_branches')->where('tenant_id', $tenant)->whereNull('deleted_at')->orderBy('name')->get(),
            'groups' => DB::table('practice_groups')->where('tenant_id', $tenant)->whereNull('deleted_at')->orderBy('name')->get(),
            'availability' => DB::table('membership_availability')->where(['tenant_id' => $tenant, 'membership_id' => MatterAccess::me($user)])->orderByDesc('starts_on')->limit(20)->get(),
            'states' => DB::table('states')->orderBy('name')->get(['code', 'name']),
            'cities' => DB::table('cities')->orderBy('name')->limit(500)->get(['id', 'name']),
            'areas' => DB::table('practice_areas')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canViewSettings' => AccessContext::tenantCan($user, 'tenant.settings.view'),
            'canUpdate' => AccessContext::tenantCan($user, 'tenant.settings.update'),
            'canBranches' => AccessContext::tenantCan($user, 'tenant.branches.manage'),
            'canGroups' => AccessContext::tenantCan($user, 'tenant.practice_groups.manage'),
            'canAvailability' => AccessContext::tenantCan($user, 'tenant.availability.manage_own'),
            'myAreas' => DB::table('membership_practice_areas')->where('membership_id', MatterAccess::me($user))->pluck('practice_area_id')->all(),
            // tenant.subscription.view: the plan and how much of it is used.
            'subscription' => AccessContext::tenantCan($user, 'tenant.subscription.view')
                ? ['plan' => app(SubscriptionLimits::class)->currentPlan($tenant), 'usage' => app(SubscriptionLimits::class)->usage($tenant)] : null,
        ]);
    }

    public function update(Request $request, AuditLogWriter $audit): RedirectResponse
    {
        $tenant = Tenant::query()->findOrFail(AccessContext::currentTenantId());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'gst_registration_type' => ['required', Rule::in(['unregistered', 'regular', 'composition'])],
            'gstin' => ['nullable', 'required_unless:gst_registration_type,unregistered', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', Rule::unique('tenants', 'gstin')->ignore($tenant->id)],
            'state_code' => ['required', 'exists:states,code'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $tenant->update($data);
        $audit->record('tenant.settings_updated', 'tenant', $tenant->id, $tenant->id);

        return back()->with('status', 'Practice details saved.');
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'state_code' => ['required', 'exists:states,code'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'is_head_office' => ['sometimes', 'boolean'],
        ]);
        $tenant = (string) AccessContext::currentTenantId();
        if (! empty($data['is_head_office']) && DB::table('tenant_branches')->where(['tenant_id' => $tenant, 'is_head_office' => true])->whereNull('deleted_at')->exists()) {
            return back()->withErrors(['is_head_office' => 'This practice already has a head office.']);
        }
        DB::table('tenant_branches')->insert($data + ['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Branch added.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'practice_area_id' => ['nullable', 'integer', 'exists:practice_areas,id']]);
        DB::table('practice_groups')->insert($data + ['id' => (string) Str::uuid7(), 'tenant_id' => AccessContext::currentTenantId(), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Practice group added.');
    }

    /** A member's own specialisations, used by the allocation matrix (PRD 2.4). */
    public function storePracticeAreas(Request $request): RedirectResponse
    {
        abort_unless(AccessContext::tenantCan($this->user($request), 'tenant.availability.manage_own'), 403);
        $data = $request->validate(['practice_area_ids' => ['nullable', 'array', 'max:20'], 'practice_area_ids.*' => ['integer', 'exists:practice_areas,id']]);
        $this->syncAreas((string) MatterAccess::me($this->user($request)), array_values(array_unique(array_map('intval', $data['practice_area_ids'] ?? []))));

        return back()->with('status', 'Your practice areas are saved.');
    }

    /** @param  list<int>  $areas */
    public static function syncAreas(string $membershipId, array $areas): void
    {
        $tenant = AccessContext::currentTenantId();
        DB::transaction(function () use ($membershipId, $areas, $tenant) {
            DB::table('membership_practice_areas')->where(['membership_id' => $membershipId, 'tenant_id' => $tenant])->delete();
            foreach ($areas as $area) {
                DB::table('membership_practice_areas')->insert(['membership_id' => $membershipId, 'tenant_id' => $tenant, 'practice_area_id' => $area, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function storeAvailability(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'availability' => ['required', Rule::in(['leave', 'partial', 'unavailable'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        DB::table('membership_availability')->insert($data + [
            'id' => (string) Str::uuid7(), 'tenant_id' => AccessContext::currentTenantId(), 'membership_id' => MatterAccess::me($this->user($request)), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('status', 'Availability saved.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
