<?php

use App\Http\Controllers\ApiDocumentationController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Docs must NOT use the "web" middleware group (EncryptCookies/StartSession).
            // Those require APP_KEY and caused production 500s while /api/v1/health still worked.
            if (! config('app.api_docs_enabled')) {
                return;
            }

            Route::get('/api/documentation', function () {
                $candidates = [
                    resource_path('swagger/ui.html'),
                    base_path('resources/swagger/ui.html'),
                ];
                foreach ($candidates as $path) {
                    if (is_readable($path)) {
                        return response((string) file_get_contents($path), 200)
                            ->header('Content-Type', 'text/html; charset=UTF-8');
                    }
                }

                return response(<<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Server API — Swagger</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.18.2/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5.18.2/swagger-ui-bundle.js"></script>
<script>
  window.onload = () => {
    SwaggerUIBundle({
      url: '/api/docs.json',
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
      layout: 'BaseLayout',
      persistAuthorization: true,
      tryItOutEnabled: true,
    });
  };
</script>
</body>
</html>
HTML, 200)->header('Content-Type', 'text/html; charset=UTF-8');
            });

            Route::get('/docs', fn () => redirect('/api/documentation'));
            Route::get('/api/docs.json', [ApiDocumentationController::class, 'spec']);
        },
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
