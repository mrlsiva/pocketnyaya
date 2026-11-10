<?php

namespace Tests\Feature\Practice;

use App\Events\RealtimeUpdate;
use App\Services\HearingService;
use App\Services\MatterService;
use App\Support\Realtime;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/** PRD 2.3 real-time signals: private channels, ids only, and never in the way of the user's action. */
class RealtimeTest extends PracticeTestCase
{
    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
        ]);
        // Channels are registered on the driver chosen at boot; register them on the one now in use.
        require base_path('routes/channels.php');
    }

    public function test_assignment_tasks_and_hearings_send_signals_to_the_right_channels(): void
    {
        $this->useReverb();
        Event::fake([RealtimeUpdate::class]);
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $junior = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);

        $this->assign($matter, $junior);
        Event::assertDispatched(RealtimeUpdate::class, fn (RealtimeUpdate $e) => $e->channel === "users.{$junior->id}" && $e->type === 'matter.assigned' && $e->data === ['matter_id' => $matter->id, 'role' => 'associate']);

        $this->inTenant($partner, $firm);
        $this->post("/practice/matters/{$matter->id}/tasks", ['title' => 'Draft reply', 'assigned_to_membership_id' => $this->membership($firm, $junior), 'priority' => 'normal'])->assertRedirect();
        Event::assertDispatched(RealtimeUpdate::class, fn (RealtimeUpdate $e) => $e->channel === "users.{$junior->id}" && $e->type === 'task.assigned');

        app(HearingService::class)->create($matter, ['court_id' => $this->court(), 'hearing_date' => now()->addDays(3)->toDateString(), 'purpose' => 'Arguments'], []);
        Event::assertDispatched(RealtimeUpdate::class, fn (RealtimeUpdate $e) => $e->channel === "tenants.{$firm}.diary" && $e->type === 'hearing.scheduled');

        // Signals carry ids only, never case content.
        Event::assertDispatched(RealtimeUpdate::class, function (RealtimeUpdate $e) use ($matter) {
            $this->assertStringNotContainsString((string) $matter->title, (string) json_encode($e->broadcastWith()));

            return true;
        });
    }

    public function test_nothing_is_sent_while_broadcasting_is_off(): void
    {
        Event::fake([RealtimeUpdate::class]);
        config(['broadcasting.default' => 'null']);
        Realtime::toUser('someone', 'task.assigned');
        Event::assertNotDispatched(RealtimeUpdate::class);
    }

    public function test_an_unreachable_server_never_breaks_the_action(): void
    {
        $this->useReverb();
        $firm = $this->firm();
        $junior = $this->member($firm, 'Junior Associate');
        $matter = $this->matter($firm);

        app(MatterService::class)->assign($this->member($firm, 'Managing Partner'), $matter, $this->membership($firm, $junior), 'associate');
        $this->assertSame('open', $matter->fresh()->status);
    }

    public function test_private_channels_admit_only_their_owner_or_firm_members_who_may_see_hearings(): void
    {
        $this->useReverb();
        $firm = $this->firm();
        $partner = $this->member($firm, 'Managing Partner');
        $finance = $this->member($firm, 'Finance Manager');
        $other = $this->member($this->firm(), 'Managing Partner');
        $auth = fn (string $channel) => $this->post('/broadcasting/auth', ['channel_name' => "private-{$channel}", 'socket_id' => '1234.5678']);

        $this->actingAs($partner);
        $auth("users.{$partner->id}")->assertOk();
        $auth("users.{$finance->id}")->assertForbidden();
        $auth("tenants.{$firm}.diary")->assertOk();

        $this->actingAs($finance);
        $auth("tenants.{$firm}.diary")->assertForbidden();

        $this->actingAs($other);
        $auth("tenants.{$firm}.diary")->assertForbidden();

        // The mobile app signs in to channels with its bearer token.
        Sanctum::actingAs($partner, ['api:read', 'api:write']);
        $this->postJson('/api/v1/broadcasting/auth', ['channel_name' => "private-users.{$partner->id}", 'socket_id' => '1234.5678'])->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', ['channel_name' => "private-users.{$finance->id}", 'socket_id' => '1234.5678'])->assertForbidden();
    }
}
