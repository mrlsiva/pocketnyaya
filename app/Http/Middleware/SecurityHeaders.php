<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for every response. Scripts may come only from this site:
 * inline scripts are refused. Inline styles are allowed, because the Bootstrap screens use
 * a few style attributes. HSTS is sent only over HTTPS, so local development keeps working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'", "script-src 'self'", "style-src 'self' 'unsafe-inline'", "img-src 'self' data:",
                "font-src 'self' data:", 'connect-src '.$this->connectSources(), "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
            ]));
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /** This site, plus the Reverb WebSocket address when real-time updates are on. */
    private function connectSources(): string
    {
        $sources = ["'self'"];
        if (config('broadcasting.default') === 'reverb') {
            $options = (array) config('broadcasting.connections.reverb.options', []);
            $secure = ($options['scheme'] ?? 'https') === 'https';
            $host = (string) ($options['host'] ?? '');
            if ($host !== '') {
                $sources[] = ($secure ? 'wss://' : 'ws://').$host.':'.(int) ($options['port'] ?? ($secure ? 443 : 80));
            }
        }

        return implode(' ', $sources);
    }
}
