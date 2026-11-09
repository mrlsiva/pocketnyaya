<?php

namespace Tests\Feature\Database;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Practice\PracticeTestCase;

/**
 * C52 isolation layer on MariaDB: a subset of the approved IS and VW tests. These run as the
 * migration account, which proves the views and procedures behave; the grants that stop
 * pn_app reaching base tables are tested separately once the accounts exist (PV tests).
 */
class IsolationLayerTest extends PracticeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Test as an unprivileged account would see the views. Rolled back with the test.
        DB::statement('DELETE FROM pn_privileged_accounts');
    }

    private function bind(User $user, string $tenant, int $ttl = 300): void
    {
        DB::statement('CALL pn_bind_context(?, ?, ?)', [$user->id, $tenant, $ttl]);
    }

    private function rowsIn(string $view): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) c FROM {$view}")->c;
    }

    private function sqlState(callable $call): string
    {
        try {
            $call();
        } catch (QueryException $e) {
            return (string) ($e->errorInfo[1] ?? '').' '.$e->getMessage();
        }

        return 'no error';
    }

    protected function tearDown(): void
    {
        DB::statement('CALL pn_clear_context()');
        parent::tearDown();
    }

    public function test_views_show_only_the_bound_practice_and_nothing_without_a_context(): void
    {
        $a = $this->firm();
        $b = $this->firm();
        $member = $this->member($a, 'Managing Partner');
        $this->matter($a);
        $this->matter($b);
        $this->matter($b);

        $this->assertSame(0, $this->rowsIn('v_matters'), 'No context means no rows.');
        $this->bind($member, $a);
        $this->assertSame(1, $this->rowsIn('v_matters'));
        $this->assertSame(1, $this->rowsIn('v_clients'));
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM v_matters WHERE tenant_id = ?', [$b])->c);

        DB::statement('CALL pn_clear_context()');
        $this->assertSame(0, $this->rowsIn('v_matters'));
    }

    public function test_binding_needs_an_active_membership_in_an_active_practice(): void
    {
        $a = $this->firm();
        $b = $this->firm();
        $member = $this->member($a, 'Managing Partner');

        $this->assertStringContainsString('PN_CTX_DENIED', $this->sqlState(fn () => $this->bind($member, $b)));
        $this->assertStringContainsString('PN_CTX_FIELD', $this->sqlState(fn () => $this->bind($member, 'not-a-uuid')));
        $this->assertStringContainsString('PN_CTX_FIELD', $this->sqlState(fn () => $this->bind($member, $a, 99999)));

        DB::table('tenants')->where('id', $a)->update(['status' => 'suspended']);
        $this->assertStringContainsString('PN_CTX_DENIED', $this->sqlState(fn () => $this->bind($member, $a)));
    }

    public function test_deactivation_and_expiry_take_effect_on_the_next_statement(): void
    {
        $a = $this->firm();
        $member = $this->member($a, 'Managing Partner');
        $this->matter($a);
        $this->bind($member, $a);
        $this->assertSame(1, $this->rowsIn('v_matters'));

        DB::table('users')->where('id', $member->id)->update(['status' => 'suspended']);
        $this->assertSame(0, $this->rowsIn('v_matters'), 'A suspended user sees nothing at once.');
        DB::table('users')->where('id', $member->id)->update(['status' => 'active']);
        $this->assertSame(1, $this->rowsIn('v_matters'));

        DB::table('pn_access_context')->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6) - INTERVAL 1 SECOND')]);
        $this->assertSame(0, $this->rowsIn('v_matters'), 'An expired context sees nothing.');
        $this->assertStringContainsString('PN_CTX_MISSING', $this->sqlState(fn () => DB::statement('CALL pn_assert_context()')));
    }

    public function test_check_option_stops_writing_into_another_practice(): void
    {
        $a = $this->firm();
        $b = $this->firm();
        $member = $this->member($a, 'Managing Partner');
        $client = $this->client($a);
        $otherClient = $this->client($b);
        $this->bind($member, $a);

        // Error 1369: CHECK OPTION failed.
        $this->assertStringContainsString('1369', $this->sqlState(fn () => DB::table('v_clients')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $b, 'client_type' => 'individual', 'name' => 'Smuggled', 'state_code' => '33'])));
        $this->assertStringContainsString('1369', $this->sqlState(fn () => DB::table('v_clients')->where('id', $client->id)->update(['tenant_id' => $b])));
        $this->assertSame(0, DB::table('v_clients')->where('id', $otherClient->id)->update(['name' => 'Changed']), 'Another practice\'s rows are out of reach.');
        $this->assertSame(0, DB::table('v_clients')->where('id', $otherClient->id)->delete());

        DB::table('v_clients')->insert(['id' => $id = (string) Str::uuid(), 'tenant_id' => $a, 'client_type' => 'individual', 'name' => 'Through the view', 'state_code' => '33']);
        $this->assertSame('Through the view', DB::table('clients')->where('id', $id)->value('name'));
        $this->assertSame(1, DB::table('v_clients')->where('id', $id)->update(['name' => 'Renamed']));
    }

    public function test_writes_through_views_still_fire_the_base_table_triggers(): void
    {
        $a = $this->firm();
        $member = $this->member($a, 'Managing Partner');
        $junior = $this->member($a, 'Junior Associate');
        $rolesOf = fn (User $u) => DB::table('model_has_roles')->where(['team_id' => $a, 'model_id' => $u->id])->count();
        $this->assertSame(1, $rolesOf($junior));
        $this->bind($member, $a);

        // Ending a membership through the view runs the base table's role-revocation trigger.
        $this->assertSame(1, DB::table('v_tenant_memberships')->where('user_id', $junior->id)->update(['status' => 'left']));
        $this->assertSame(0, $rolesOf($junior));
        $this->assertSame(1, $rolesOf($member));
    }

    public function test_personal_vault_view_needs_a_user_context_and_shows_only_the_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$owner, $other] as $user) {
            $document = new Document(['title' => 'Vault', 'category' => 'other']);
            $document->forceFill(['scope' => 'personal', 'owner_user_id' => $user->id])->save();
        }

        $this->assertSame(0, $this->rowsIn('v_personal_documents'));
        DB::statement('CALL pn_bind_user_context(?, ?)', [$owner->id, 300]);
        $this->assertSame(1, $this->rowsIn('v_personal_documents'));
        $this->assertSame($owner->id, DB::table('v_personal_documents')->value('owner_user_id'));
        $this->assertSame(0, $this->rowsIn('v_tenant_documents'), 'A user context sees no firm documents.');
    }

    public function test_grant_script_gives_pn_app_views_only_and_prints_no_real_password(): void
    {
        $this->assertSame(0, Artisan::call('pocketnyaya:isolation-grants'));
        $sql = Artisan::output();
        $app = array_filter(explode(PHP_EOL, $sql), fn ($l) => str_contains($l, "'pn_app'@"));

        $this->assertStringContainsString("IDENTIFIED BY 'REPLACE_WITH_A_STRONG_PASSWORD_FOR_PN_APP'", $sql);
        foreach ($app as $line) {
            $this->assertMatchesRegularExpression('/(ON `[^`]+`\.`v_[a-z_]+` TO|ON PROCEDURE|CREATE USER)/', $line, "pn_app may reach views and procedures only: {$line}");
        }
        $this->assertMatchesRegularExpression('/GRANT SELECT, INSERT, UPDATE, DELETE ON `[^`]+`\.`v_matters` TO .pn_app/', $sql);
        $this->assertMatchesRegularExpression('/GRANT SELECT, INSERT ON `[^`]+`\.`v_audit_logs` TO .pn_app/', $sql);
        $this->assertMatchesRegularExpression('/GRANT EXECUTE ON PROCEDURE `[^`]+`\.`pn_bind_context` TO .pn_app/', $sql);
        $this->assertMatchesRegularExpression('/GRANT SELECT, INSERT, UPDATE, DELETE ON `[^`]+`\.`matters` TO .pn_platform/', $sql);
        $this->assertDoesNotMatchRegularExpression('/`(pn_access_context|pn_privileged_accounts)` TO/', $sql);
        $this->assertDoesNotMatchRegularExpression('/GRANT (ALL|.*(DROP|ALTER|CREATE))/', $sql);
    }

    public function test_every_firm_table_has_a_writable_checked_view(): void
    {
        $views = collect(DB::select("SELECT TABLE_NAME n, IS_UPDATABLE u, CHECK_OPTION c FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v\\_%'"))->keyBy('n');
        foreach (['v_clients', 'v_matters', 'v_invoices', 'v_tenant_memberships', 'v_time_entries', 'v_tenant_documents', 'v_personal_documents'] as $view) {
            $this->assertSame('YES', $views[$view]->u ?? null, "{$view} must be updatable");
            $this->assertSame('CASCADED', $views[$view]->c ?? null, "{$view} must have CASCADED CHECK OPTION");
        }
    }
}
