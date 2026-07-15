<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Defense in depth when Nginx is absent (artisan serve / tests).
        // Skip headers already set by the reverse proxy to avoid duplicates.
        $this->setMissing($response, 'X-Content-Type-Options', 'nosniff');
        $this->setMissing($response, 'X-Frame-Options', 'SAMEORIGIN');
        $this->setMissing($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->setMissing($response, 'X-XSS-Protection', '0');
        $this->setMissing(
            $response,
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        );
        $this->setMissing($response, 'Cross-Origin-Opener-Policy', 'same-origin');
        $this->setMissing($response, 'Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->remove('X-Powered-By');

        $csp = $this->contentSecurityPolicy($request);
        $this->setMissing($response, 'Content-Security-Policy', $csp);

        if ($request->secure()) {
            $this->setMissing($response, 'Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $base = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none';";
        $fonts = "font-src 'self' data: https://fonts.gstatic.com;";
        $images = "img-src 'self' data: blob:;";

        if ($this->isApiDocsRequest($request)) {
            // Self-hosted /docs-assets/* — works with host Nginx CSP (script-src 'self')
            return "{$base} script-src 'self'; style-src 'self' 'unsafe-inline'; {$fonts} {$images} connect-src 'self';";
        }

        return "{$base} script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; {$fonts} {$images} connect-src 'self';";
    }

    private function isApiDocsRequest(Request $request): bool
    {
        return in_array($request->path(), ['api/documentation', 'api/docs.json', 'docs'], true);
    }

    private function setMissing(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
