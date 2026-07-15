<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
            if (! class_exists(\OpenApi\Generator::class)) {
                return response()->json([
                    'message' => 'OpenAPI package missing. Run composer install in the app container.',
                    'error' => 'Class OpenApi\\Generator not found',
                ], 500);
            }

            $openapi = \OpenApi\Generator::scan([
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
        try {
            $path = resource_path('swagger/ui.html');
            if (! is_readable($path)) {
                return response(
                    '<!DOCTYPE html><html><body><h1>API docs UI missing</h1><p>Expected file: resources/swagger/ui.html</p></body></html>',
                    500
                )->header('Content-Type', 'text/html; charset=UTF-8');
            }

            return response((string) file_get_contents($path), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (Throwable $e) {
            report($e);

            return response(
                '<!DOCTYPE html><html><body><h1>API docs error</h1><pre>'.e($e->getMessage()).'</pre></body></html>',
                500
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }
    }
}
