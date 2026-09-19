<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\MigrationExportService;
use App\Services\MigrationImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MigrationController extends Controller
{
    public function export(
        Request $request,
        MigrationExportService $exporter,
        AuditLogService $audit,
    ): StreamedResponse {
        $package = $exporter->build();

        $audit->log('Migration package exported', [
            'event_type' => 'migration_exported',
            'user' => $request->user(),
            'http_method' => 'GET',
            'request_uri' => $request->path(),
            'new_values' => [
                'providers' => count($package['email_providers'] ?? []),
                'users' => count($package['users'] ?? []),
                'clients' => count($package['external_integrations'] ?? []),
            ],
        ]);

        $filename = $exporter->filename();
        $payload = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(
            function () use ($payload): void {
                echo $payload;
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function import(
        Request $request,
        MigrationImportService $importer,
        AuditLogService $audit,
    ): JsonResponse {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimetypes:application/json,text/plain,text/json'],
        ]);

        $raw = file_get_contents($request->file('file')->getRealPath() ?: '');
        $package = json_decode(is_string($raw) ? $raw : '', true);
        if (! is_array($package)) {
            return response()->json(['message' => 'Invalid migration JSON.'], 422);
        }

        try {
            $summary = $importer->import($package);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $audit->log('Migration package imported', [
            'event_type' => 'migration_imported',
            'user' => $request->user(),
            'http_method' => 'POST',
            'request_uri' => $request->path(),
            'new_values' => collect($summary)->except('warnings')->all(),
        ]);

        return response()->json([
            'message' => 'Migration imported.',
            'data' => $summary,
        ]);
    }
}
