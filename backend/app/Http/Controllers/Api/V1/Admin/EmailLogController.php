<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\ExternalIntegration;
use App\Services\EmailDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class EmailLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = EmailLog::query()
            ->with(['emailProvider:id,name', 'externalIntegration:id,name'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->query('status'))
            )
            ->when(
                $request->filled('external_integration_id'),
                function ($q) use ($request) {
                    $client = (string) $request->query('external_integration_id');
                    if ($client === 'none' || $client === '0') {
                        $q->whereNull('external_integration_id');
                    } else {
                        $q->where('external_integration_id', (int) $client);
                    }
                }
            )
            ->when(
                $request->filled('q'),
                function ($q) use ($request) {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('q')).'%';
                    $q->where(function ($inner) use ($term) {
                        $inner->where('to', 'like', $term)
                            ->orWhere('subject', 'like', $term)
                            ->orWhere('error_message', 'like', $term);
                    });
                }
            )
            ->latest()
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => $logs->getCollection()->map->toLogArray()->values(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        $clients = ExternalIntegration::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ExternalIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
            ])
            ->values();

        return response()->json([
            'data' => [
                'statuses' => [
                    ['value' => 'pending', 'label' => 'Pending'],
                    ['value' => 'sent', 'label' => 'Sent'],
                    ['value' => 'failed', 'label' => 'Failed'],
                ],
                'clients' => $clients,
            ],
        ]);
    }

    public function retry(EmailLog $emailLog, EmailDispatchService $dispatch): JsonResponse
    {
        try {
            $log = $dispatch->retry($emailLog);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not queue email for resend.',
            ], 500);
        }

        $log->load(['emailProvider:id,name', 'externalIntegration:id,name']);

        return response()->json([
            'message' => 'Email queued for resend.',
            'data' => $log->toLogArray(),
        ]);
    }

    public function retryFailed(Request $request, EmailDispatchService $dispatch): JsonResponse
    {
        $client = $request->filled('external_integration_id')
            ? (string) $request->input('external_integration_id')
            : null;

        try {
            $result = $dispatch->retryAllFailed($client);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not queue failed emails for resend.',
            ], 500);
        }

        $queued = $result['queued'];
        $skipped = $result['skipped'];

        if ($queued === 0 && $skipped === 0) {
            return response()->json([
                'message' => 'No failed emails found to resend.',
                'queued' => 0,
                'skipped' => 0,
            ]);
        }

        return response()->json([
            'message' => $skipped > 0
                ? "Queued {$queued} failed email(s) for resend. Skipped {$skipped} without a stored body."
                : "Queued {$queued} failed email(s) for resend.",
            'queued' => $queued,
            'skipped' => $skipped,
        ]);
    }
}
