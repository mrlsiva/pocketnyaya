<?php

namespace Tests\Feature\Practice;

use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use App\Services\MatterService;
use App\Services\RoleAssignmentService;
use Database\Seeders\PlatformSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Uses the real seeded roles. Synthetic data, rolled back after each test. */
abstract class PracticeTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('states')->insertOrIgnore(['code' => '33', 'name' => 'Tamil Nadu']);
        $this->seed([RoleSeeder::class, PlatformSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    protected function firm(string $type = 'firm'): string
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id, 'type' => $type, 'name' => 'Firm '.$id, 'slug' => 'f-'.Str::lower(Str::random(14)),
            'gst_registration_type' => 'unregistered', 'state_code' => '33',
        ]);

        return $id;
    }

    protected function member(string $tenant, string $role): User
    {
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $user->id, 'status' => 'active']);
        app(RoleAssignmentService::class)->assign($user, $role, $tenant);

        return $user;
    }

    protected function membership(string $tenant, User $user): string
    {
        return (string) DB::table('tenant_memberships')->where(['tenant_id' => $tenant, 'user_id' => $user->id])->value('id');
    }

    /** Puts this user in the given tenant, for both Gate checks and web requests. */
    protected function inTenant(User $user, string $tenant): static
    {
        setPermissionsTeamId($tenant);

        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant]);
    }

    protected function client(string $tenant, string $name = 'Synthetic Client'): Client
    {
        $client = new Client(['client_type' => 'individual', 'name' => $name, 'state_code' => '33']);
        $client->forceFill(['tenant_id' => $tenant])->save();

        return $client;
    }

    protected function matter(string $tenant, ?Client $client = null, string $status = 'open'): Matter
    {
        $matter = new Matter(['client_id' => ($client ?? $this->client($tenant))->id, 'title' => 'Synthetic Matter', 'matter_type' => 'litigation', 'priority' => 'normal']);
        $matter->forceFill([
            'tenant_id' => $tenant, 'matter_number' => 'T-'.Str::random(10), 'status' => $status, 'opened_at' => now(),
            'created_by' => User::factory()->create()->id, 'metadata' => [],
        ])->save();

        return $matter;
    }

    protected function assign(Matter $matter, User $user, string $role = 'associate'): void
    {
        $previous = getPermissionsTeamId();
        app(MatterService::class)->assign($user, $matter, $this->membership($matter->tenant_id, $user), $role);
        setPermissionsTeamId($previous);
    }

    protected function court(): int
    {
        $state = (int) DB::table('states')->where('code', '33')->value('id');

        return (int) DB::table('courts')->insertGetId(['name' => 'Court '.Str::random(8), 'court_type' => 'district', 'state_id' => $state]);
    }
}
