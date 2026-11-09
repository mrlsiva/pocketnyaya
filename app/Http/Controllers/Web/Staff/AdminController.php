<?php

namespace App\Http\Controllers\Web\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantOffboarding;
use App\Models\User;
use App\Services\AuditLogWriter;
use App\Services\SecurityRevocationService;
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

        return view('staff.admin.tenants', [
            'tenants' => Tenant::query()->where('type', '<>', 'platform')
                ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('name')->paginate(25, ['id', 'type', 'name', 'status', 'created_at'])->withQueryString(),
            'search' => $search,
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
