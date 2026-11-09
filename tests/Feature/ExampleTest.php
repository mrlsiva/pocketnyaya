<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_page_loads_with_the_disclaimer(): void
    {
        $this->withoutVite()
            ->get('/')
            ->assertOk()
            ->assertSee('does not advertise or solicit legal work');
    }

    public function test_api_ping_returns_json(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'version' => 'v1']);
    }

    public function test_unknown_api_route_returns_json_404_without_an_accept_header(): void
    {
        $response = $this->get('/api/v1/does-not-exist');

        $response->assertNotFound();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_tokens_expire_after_14_days_and_cookie_mode_is_off(): void
    {
        $this->assertSame(20160, config('sanctum.expiration'));
        $this->assertSame([], config('sanctum.stateful'));
        $this->assertSame([], config('sanctum.guard'));
    }
}
