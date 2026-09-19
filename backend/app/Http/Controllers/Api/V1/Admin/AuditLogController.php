<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->integer('per_page', 50)));

        $query = AuditLog::query()->orderByDesc('id');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('action', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhere('request_uri', 'like', $like)
                    ->orWhere('target_table', 'like', $like)
                    ->orWhere('event_type', 'like', $like)
                    ->orWhere('ip_address', 'like', $like);
            });
        }

        if ($request->filled('name')) {
            $query->where('user_name', 'like', '%'.trim((string) $request->query('name')).'%');
        }

        if ($request->filled('email')) {
            $query->where('user_email', 'like', '%'.trim((string) $request->query('email')).'%');
        }

        if ($request->filled('http_method')) {
            $query->where('http_method', strtoupper((string) $request->query('http_method')));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', 'like', '%'.trim((string) $request->query('event_type')).'%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('date_to'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user_name,
                'user_email' => $log->user_email,
                'action' => $log->action,
                'event_type' => $log->event_type,
                'http_method' => $log->http_method,
                'request_uri' => $log->request_uri,
                'target_table' => $log->target_table,
                'target_id' => $log->target_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'extended' => true,
            ],
        ]);
    }
}
