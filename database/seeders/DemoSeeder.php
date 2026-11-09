<?php

namespace Database\Seeders;

use App\Models\AdvocateProfile;
use App\Models\MediatorProfile;
use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Support\AccessContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic demonstration accounts for local development only. Safe to re-run.
 * Everything here is invented, and the shared password is public by design.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'PocketDemo2026';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo accounts are only for local development.');
        }

        $roles = app(RoleAssignmentService::class);
        $platform = (string) AccessContext::platformTenantId();

        $solo = $this->tenant('solo', 'Demo Advocate Chambers', 'demo-advocate-chambers');
        $firm = $this->tenant('firm', 'Demo Law Associates', 'demo-law-associates');

        $this->person('customer@pocketnyaya.test', 'Demo Customer', $roles);

        $lawyer = $this->person('lawyer@pocketnyaya.test', 'Demo Independent Lawyer', $roles);
        DB::table('tenants')->where('id', $solo)->update(['owner_user_id' => $lawyer->id]);
        $this->member($lawyer, $solo, 'Independent Lawyer', $roles);
        $this->advocate($lawyer, 'DEMO/1001/2012');

        foreach ([
            ['partner@pocketnyaya.test', 'Demo Managing Partner', 'Managing Partner', 'DEMO/1002/2008'],
            ['admin@pocketnyaya.test', 'Demo Firm Admin', 'Firm Admin', null],
            ['senior@pocketnyaya.test', 'Demo Senior Associate', 'Senior Associate', 'DEMO/1003/2014'],
            ['junior@pocketnyaya.test', 'Demo Junior Associate', 'Junior Associate', 'DEMO/1004/2021'],
            ['finance@pocketnyaya.test', 'Demo Finance Manager', 'Finance Manager', null],
            ['clerk@pocketnyaya.test', 'Demo Clerk', 'Clerk / Office Staff', null],
        ] as [$email, $name, $role, $enrollment]) {
            $person = $this->person($email, $name, $roles);
            $this->member($person, $firm, $role, $roles);
            if ($enrollment !== null) {
                $this->advocate($person, $enrollment);
            }
        }
        DB::table('tenants')->where('id', $firm)->update(['owner_user_id' => User::query()->where('email', 'partner@pocketnyaya.test')->value('id')]);

        foreach ([['compliance@pocketnyaya.test', 'Demo Compliance Officer', 'Compliance Officer'], ['superadmin@pocketnyaya.test', 'Demo Super Admin', 'Super Admin'], ['superadmin2@pocketnyaya.test', 'Demo Second Super Admin', 'Super Admin']] as [$email, $name, $role]) {
            $this->member($this->person($email, $name, $roles), $platform, $role, $roles);
        }

        $mediator = $this->person('mediator@pocketnyaya.test', 'Demo Mediator', $roles);
        MediatorProfile::query()->firstOrNew(['user_id' => $mediator->id])->forceFill([
            'user_id' => $mediator->id, 'accreditation_body' => 'Demo Accreditation Body',
            'accreditation_number' => 'DEMO-MED-1', 'verification_status' => 'verified', 'is_available' => true,
        ])->save();
        $this->assignOnce($mediator, 'Mediator', $platform, $roles);
    }

    private function person(string $email, string $name, RoleAssignmentService $roles): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
            $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
            $roles->assignCustomer($user);
        }

        return $user;
    }

    private function tenant(string $type, string $name, string $slug): string
    {
        $id = DB::table('tenants')->where('slug', $slug)->value('id');
        if (is_string($id)) {
            return $id;
        }

        $id = (string) Str::uuid7();
        DB::table('tenants')->insert([
            'id' => $id, 'type' => $type, 'name' => $name, 'slug' => $slug,
            'gst_registration_type' => 'unregistered', 'state_code' => '33',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function member(User $user, string $tenant, string $role, RoleAssignmentService $roles): void
    {
        DB::table('tenant_memberships')->updateOrInsert(
            ['tenant_id' => $tenant, 'user_id' => $user->id],
            ['id' => (string) Str::uuid7(), 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );
        $this->assignOnce($user, $role, $tenant, $roles);
    }

    private function assignOnce(User $user, string $role, string $tenant, RoleAssignmentService $roles): void
    {
        $held = DB::table('model_has_roles as r')->join('roles', 'roles.id', '=', 'r.role_id')
            ->where(['r.team_id' => $tenant, 'r.model_id' => $user->id, 'roles.name' => $role])->exists();
        if (! $held) {
            $roles->assign($user, $role, $tenant);
        }
    }

    private function advocate(User $user, string $enrollment): void
    {
        if (AdvocateProfile::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        (new AdvocateProfile)->forceFill([
            'user_id' => $user->id,
            'bar_council_id' => DB::table('bar_councils')->where('code', 'BCTN')->value('id'),
            'enrollment_number' => $enrollment,
            'enrollment_date' => '2015-06-15',
            'city_id' => DB::table('cities')->where('name', 'Chennai')->value('id'),
            'verification_status' => 'draft',
        ])->save();
    }
}
