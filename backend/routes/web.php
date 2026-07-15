<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', HealthController::class);

if (config('app.api_docs_enabled')) {
    Route::redirect('/', '/api/documentation');

    // Zero-dependency HTML UI (no Blade, no OpenAPI class load) — avoids opaque 500s
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

        // Absolute last resort — still return HTML so the browser is not stuck on JSON
        return response(<<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
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
} else {
    Route::redirect('/', '/api/health');
}
