<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AuditLogController extends Controller
{
    public function filterOptions(): JsonResponse
    {
        $eventTypes = AuditLog::query()
            ->whereNotNull('event_type')
            ->where('event_type', '!=', '')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->values()
            ->all();

        $targetTables = AuditLog::query()
            ->whereNotNull('target_table')
            ->where('target_table', '!=', '')
            ->distinct()
            ->orderBy('target_table')
            ->pluck('target_table')
            ->values()
            ->all();

        $methods = AuditLog::query()
            ->whereNotNull('http_method')
            ->where('http_method', '!=', '')
            ->distinct()
            ->orderBy('http_method')
            ->pluck('http_method')
            ->values()
            ->all();

        $actorTypes = Schema::hasColumn('audit_logs', 'actor_type')
            ? AuditLog::query()
                ->whereNotNull('actor_type')
                ->where('actor_type', '!=', '')
                ->distinct()
                ->orderBy('actor_type')
                ->pluck('actor_type')
                ->values()
                ->all()
            : [AuditLogService::ACTOR_SYSTEM_USER, AuditLogService::ACTOR_CLIENT];

        if ($actorTypes === []) {
            $actorTypes = [AuditLogService::ACTOR_SYSTEM_USER, AuditLogService::ACTOR_CLIENT];
        }

        return response()->json([
            'data' => [
                'event_types' => $eventTypes,
                'target_tables' => $targetTables,
                'http_methods' => $methods !== [] ? $methods : ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'actor_types' => $actorTypes,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->integer('per_page', 50)));

        $query = AuditLog::query()
            ->with(['externalIntegration:id,name,slug'])
            ->orderByDesc('id');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('action', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhere('request_uri', 'like', $like)
                    ->orWhere('target_table', 'like', $like)
                    ->orWhere('target_id', 'like', $like)
                    ->orWhere('event_type', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('suspicious_reasons', 'like', $like)
                    ->orWhere('actor_type', 'like', $like);
            });
        }

        if ($request->filled('name')) {
            $query->where('user_name', 'like', '%'.trim((string) $request->query('name')).'%');
        }

        if ($request->filled('email')) {
            $query->where('user_email', 'like', '%'.trim((string) $request->query('email')).'%');
        }

        if ($request->filled('ip_address')) {
            $query->where('ip_address', 'like', '%'.trim((string) $request->query('ip_address')).'%');
        }

        if ($request->filled('http_method')) {
            $query->where('http_method', strtoupper((string) $request->query('http_method')));
        }

        if ($request->filled('event_type')) {
            $eventType = trim((string) $request->query('event_type'));
            if ($request->boolean('event_type_exact')) {
                $query->where('event_type', $eventType);
            } else {
                $query->where('event_type', 'like', '%'.$eventType.'%');
            }
        }

        if ($request->filled('target_table')) {
            $query->where('target_table', trim((string) $request->query('target_table')));
        }

        if (Schema::hasColumn('audit_logs', 'actor_type') && $request->filled('actor_type')) {
            $query->where('actor_type', trim((string) $request->query('actor_type')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('date_to'));
        }

        if (Schema::hasColumn('audit_logs', 'is_suspicious') && $request->filled('suspicious')) {
            $suspicious = $request->query('suspicious');
            if ($suspicious === '1' || $suspicious === 1 || $suspicious === true || $suspicious === 'true') {
                $query->where('is_suspicious', true);
            } elseif ($suspicious === '0' || $suspicious === 0 || $suspicious === false || $suspicious === 'false') {
                $query->where('is_suspicious', false);
            }
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor_type' => $log->actor_type ?? AuditLogService::ACTOR_SYSTEM_USER,
                'actor_label' => match ($log->actor_type ?? AuditLogService::ACTOR_SYSTEM_USER) {
                    AuditLogService::ACTOR_CLIENT => 'Client',
                    AuditLogService::ACTOR_SYSTEM => 'System',
                    default => 'System user',
                },
                'user_id' => $log->user_id,
                'user_name' => $log->user_name,
                'user_email' => $log->user_email,
                'external_integration_id' => $log->external_integration_id,
                'external_integration' => $log->externalIntegration ? [
                    'id' => $log->externalIntegration->id,
                    'name' => $log->externalIntegration->name,
                    'slug' => $log->externalIntegration->slug,
                ] : null,
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
                'is_suspicious' => (bool) ($log->is_suspicious ?? false),
                'suspicious_reasons' => $log->suspicious_reasons,
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
