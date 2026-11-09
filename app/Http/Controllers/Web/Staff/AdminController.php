<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\SecurityRevocationService;
use App\Services\SubscriptionLimits;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Platform administration. Metadata only: nothing here opens firm or personal content (DA-1). */
class AdminController extends Controller
{
    public function __construct(private AuditLogWriter $audit) {}

    public function tenants(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $tenants = Tenant::query()->where('type', '<>', 'platform')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')->paginate(25, ['id', 'type', 'name', 'status', 'created_at'])->withQueryString();
        $limits = app(SubscriptionLimits::class);
        /** @var array<string, string|null> $currentPlans */
        $currentPlans = [];
        foreach ($tenants->items() as $t) {
            $currentPlans[(string) $t->id] = $limits->currentPlan((string) $t->id)?->id;
        }

        return view('staff.admin.tenants', [
            'tenants' => $tenants,
            'search' => $search,
            'currentPlans' => $currentPlans,
            'plans' => DB::table('subscription_plans')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManagePlans' => $request->user() !== null && AccessContext::staffCan($request->user(), 'platform.subscription_plans.manage'),
        ]);
    }

    public function updateTenant(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_if($tenant->type === 'platform', 404);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => ['required', 'string', 'max:500']]);
        abort_if($tenant->status === 'closed', 422, 'A closed practice cannot be changed.');
        abort_if(TenantOffboarding::query()->where('tenant_id', $tenant->id)->whereIn('status', ['requested', 'suspended', 'export_open'])->exists(), 422, 'This practice is being closed. Cancel the closure to change its status.');

        $old = $tenant->status;
        DB::transaction(function () use ($tenant, $data, $old) {
            $tenant->forceFill(['status' => $data['status']])->save();
            $this->audit->record('platform.tenant_status_changed', 'tenant', $tenant->id, $tenant->id, ['status' => $old], ['status' => $data['status'], 'reason' => $data['reason']]);
        });

        return back()->with('status', 'Practice updated.');
    }

    public function users(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('staff.admin.users', [
            'users' => User::query()->whereNull('erased_at')
                ->when($search !== '', function ($q) use ($search) {
                    $like = '%'.addcslashes($search, '%_\\').'%';
                    $q->where(fn ($s) => $s->where('name', 'like', $like)->orWhere('email', 'like', mb_strtolower($like)));
                })->orderBy('name')->paginate(25, ['id', 'name', 'email', 'status', 'last_login_at', 'created_at'])->withQueryString(),
            'search' => $search,
        ]);
    }

    public function updateUser(Request $request, User $person, SecurityRevocationService $revocation): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        abort_if($person->id === $staff->id, 403, 'You cannot change your own account here.');
        abort_if($person->status === 'erased', 422);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => ['required', 'string', 'max:500']]);

        $old = $person->status;
        DB::transaction(function () use ($person, $data, $old, $revocation) {
            $person->forceFill(['status' => $data['status']])->save();
            if ($data['status'] === 'suspended') {
                $revocation->revokeAll($person);
            }
            $this->audit->record('platform.user_status_changed', 'user', $person->id, null, ['status' => $old], ['status' => $data['status'], 'reason' => $data['reason']]);
        });

        return back()->with('status', 'Account updated.');
    }

    public function settings(): View
    {
        return view('staff.admin.settings', ['settings' => DB::table('platform_settings')->orderBy('key')->get()]);
    }

    public function updateSetting(Request $request): RedirectResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $data = $request->validate(['key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/'], 'value' => ['required', 'string', 'max:2000']]);

        DB::table('platform_settings')->updateOrInsert(['key' => $data['key']], ['value' => json_encode($data['value']), 'updated_by' => $staff->id, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('platform.setting_changed', 'platform_setting', $data['key']);

        return back()->with('status', 'Setting saved.');
    }

    public function plans(): View
    {
        return view('staff.admin.plans', ['plans' => DB::table('subscription_plans')->orderBy('name')->get()]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'unique:subscription_plans,code'],
            'name' => ['required', 'string', 'max:120'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        DB::table('subscription_plans')->insert($data + ['id' => (string) Str::uuid7(), 'features' => '[]', 'limits' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Plan added.');
    }

    /** Empty means unlimited. Limits stop new additions only; existing data is never removed. */
    public function updatePlanLimits(Request $request, string $plan): RedirectResponse
    {
        abort_if(DB::table('subscription_plans')->where('id', $plan)->doesntExist(), 404);
        $rules = [];
        foreach (array_keys(SubscriptionLimits::LIMITS) as $key) {
            $rules[$key] = ['nullable', 'integer', 'min:0', 'max:10000000'];
        }
        $data = array_filter($request->validate($rules), fn ($v) => $v !== null && $v !== '');
        DB::table('subscription_plans')->where('id', $plan)->update(['limits' => json_encode(array_map('intval', $data)), 'updated_at' => now()]);
        $this->audit->record('platform.plan_limits_changed', 'subscription_plan', $plan, null, null, $data);

        return back()->with('status', 'Plan limits saved.');
    }

    /** Puts a practice on a plan. Any current plan ends now, and the record of it is kept. */
    public function assignPlan(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_if($tenant->type === 'platform' || $tenant->status === 'closed', 404);
        $data = $request->validate(['plan_id' => ['nullable', 'uuid']]);
        abort_if(! empty($data['plan_id']) && DB::table('subscription_plans')->where(['id' => $data['plan_id'], 'is_active' => true])->doesntExist(), 422, 'That plan is not available.');

        DB::transaction(function () use ($tenant, $data) {
            DB::table('tenant_subscriptions')->where('tenant_id', $tenant->id)->whereIn('status', ['trialing', 'active', 'past_due'])
                ->update(['status' => 'cancelled', 'ends_at' => now(), 'updated_at' => now()]);
            if (! empty($data['plan_id'])) {
                DB::table('tenant_subscriptions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'plan_id' => $data['plan_id'], 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('platform.plan_assigned', 'tenant', $tenant->id, $tenant->id, null, ['plan_id' => $data['plan_id'] ?? null]);
        });

        return back()->with('status', empty($data['plan_id']) ? 'Plan removed. The practice has no limits.' : 'Plan assigned.');
    }

    public function togglePlan(string $plan): RedirectResponse
    {
        $row = DB::table('subscription_plans')->where('id', $plan)->first();
        abort_if($row === null, 404);
        DB::table('subscription_plans')->where('id', $plan)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);

        return back()->with('status', 'Plan updated.');
    }

    public function reference(): View
    {
        return view('staff.admin.reference', [
            'areas' => DB::table('practice_areas')->orderBy('name')->get(['id', 'name', 'is_active']),
            'languages' => DB::table('languages')->orderBy('name')->get(['id', 'code', 'name']),
            'courts' => DB::table('courts')->orderBy('name')->limit(200)->get(['id', 'name', 'court_type', 'is_active']),
            'cities' => DB::table('cities')->orderBy('name')->limit(200)->get(['id', 'name']),
            'states' => DB::table('states')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeReference(Request $request, string $type): RedirectResponse
    {
        $now = ['created_at' => now(), 'updated_at' => now()];
        match ($type) {
            'practice-area' => DB::table('practice_areas')->insert($this->validatedArea($request) + $now),
            'language' => DB::table('languages')->insert($request->validate(['code' => ['required', 'string', 'max:10', 'unique:languages,code'], 'name' => ['required', 'string', 'max:80']]) + $now),
            'city' => DB::table('cities')->insert($request->validate(['name' => ['required', 'string', 'max:120'], 'state_id' => ['required', 'integer', 'exists:states,id']]) + $now),
            'court' => DB::table('courts')->insert($request->validate(['name' => ['required', 'string', 'max:200'], 'court_type' => ['required', 'string', 'max:20'], 'state_id' => ['required', 'integer', 'exists:states,id']]) + $now),
            default => abort(404),
        };

        return back()->with('status', 'Added.');
    }

    /** @return array<string, string> */
    private function validatedArea(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $slug = Str::slug($data['name']);
        abort_if(DB::table('practice_areas')->where('slug', $slug)->exists(), 422, 'That practice area already exists.');

        return ['name' => $data['name'], 'slug' => $slug];
    }
}
