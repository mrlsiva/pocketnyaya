<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_pages_and_api_send_browser_security_headers(): void
    {
        foreach (['/', '/api/v1/ping'] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'same-origin');
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self'", $csp);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $response->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_no_view_uses_inline_scripts_the_policy_would_block(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $html = $file->getContents();
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load|input)=/i', $html, $file->getRelativePathname());
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $file->getRelativePathname());
        }
    }
}
