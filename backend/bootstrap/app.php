<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Admin UI uses Bearer tokens (localStorage), not cookie/CSRF SPA auth.
        // Never call statefulApi() — it makes SANCTUM_STATEFUL_DOMAINS (e.g.
        // notifications.africacdc.org) require CSRF and breaks browser login
        // while curl http://127.0.0.1:8089 still succeeds.
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
        $middleware->throttleApi('api');
        $middleware->trustProxies(
            // Only trust private/docker peers — never the public internet.
            // Host Nginx / compose network set X-Forwarded-* ; direct :8089 clients cannot spoof.
            at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /api/documentation is an HTML page — do not force JSON error envelopes for it
        $exceptions->shouldRenderJsonWhen(
            function (Request $request): bool {
                if ($request->is('api/documentation', 'docs')) {
                    return false;
                }

                return $request->is('api/*') || $request->expectsJson();
            },
        );
    })->create();
