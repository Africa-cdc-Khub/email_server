<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Generator;
use Throwable;

class ApiDocumentationController extends Controller
{
    public function spec(): JsonResponse
    {
        $cached = storage_path('api-docs/openapi.json');
        if (is_readable($cached)) {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode((string) file_get_contents($cached), true, 512, JSON_THROW_ON_ERROR);

                return response()->json($decoded);
            } catch (Throwable) {
                // Fall through to live generation
            }
        }

        try {
            $openapi = Generator::scan([
                app_path('OpenApi'),
            ]);

            $json = $openapi->toJson();
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            @mkdir(dirname($cached), 0775, true);
            @file_put_contents($cached, $json);

            return response()->json($decoded);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'OpenAPI generation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function ui(): Response
    {
        // Inline HTML — avoids Blade compile failures when storage/framework/views
        // on the bind-mounted volume is not writable by www-data.
        $html = <<<'HTML'
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
            defaultModelsExpandDepth: 1,
            defaultModelExpandDepth: 1,
            tryItOutEnabled: true,
        });
    };
</script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
