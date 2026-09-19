<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Staff-portal-style audit logging for admin panel actions.
 */
class AuditLogService
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'challenge_token',
        'captcha',
        'captcha_code',
        'captcha_key',
        'recaptcha_token',
        'secret',
        'client_secret',
        'recovery_codes',
        'code',
        'totp_secret',
    ];

    public function log(string $action, array $context = []): void
    {
        $request = request();
        $user = Auth::user();
        if ($user === null && isset($context['user']) && $context['user'] instanceof User) {
            $user = $context['user'];
        }

        $method = strtoupper((string) ($context['http_method'] ?? $request?->method() ?? 'GET'));
        $uri = (string) ($context['request_uri'] ?? $request?->path() ?? '');
        if (strlen($uri) > 500) {
            $uri = substr($uri, 0, 500).'…';
        }

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'action' => $action,
            'event_type' => $context['event_type'] ?? $this->inferEventType($method),
            'http_method' => $method,
            'request_uri' => $uri,
            'target_table' => $context['target_table'] ?? null,
            'target_id' => isset($context['target_id']) ? (string) $context['target_id'] : null,
            'old_values' => isset($context['old_values']) ? $this->sanitize($context['old_values']) : null,
            'new_values' => isset($context['new_values']) ? $this->sanitize($context['new_values']) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) ($request?->userAgent() ?? ''), 0, 500),
        ]);
    }

    public function logRouteAccess(Request $request): void
    {
        $route = $request->route()?->getName() ?? $request->path();
        $context = [
            'http_method' => $request->method(),
            'request_uri' => $request->path(),
        ];

        if (str_contains($request->path(), 'audit-logs')) {
            $context['event_type'] = 'audit_repository';
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $payload = $request->except(self::SENSITIVE_KEYS);
            if ($payload !== []) {
                $context['new_values'] = ['_http_request' => $payload];
                $context['event_type'] = $context['event_type'] ?? $this->inferEventType($request->method());
            }
        }

        $this->log("Accessed route: {$route}", $context);
    }

    public function logRecordChange(
        string $eventType,
        string $targetTable,
        string|int $targetId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $action = null,
    ): void {
        $this->log($action ?? "Record audit: {$targetTable} #{$targetId} — {$eventType}", [
            'event_type' => 'record_'.preg_replace('/[^a-z0-9_]+/i', '', $eventType),
            'target_table' => $targetTable,
            'target_id' => (string) $targetId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    protected function inferEventType(string $method): string
    {
        return match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'access',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function sanitize(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $keyStr = (string) $key;
            if (in_array(strtolower($keyStr), self::SENSITIVE_KEYS, true)
                || str_contains(strtolower($keyStr), 'password')
                || str_contains(strtolower($keyStr), 'secret')
            ) {
                $out[$keyStr] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                if (strtolower($keyStr) === 'attachments') {
                    $out[$keyStr] = array_map(function ($item) {
                        if (! is_array($item)) {
                            return '[attachment]';
                        }

                        return [
                            'filename' => $item['filename'] ?? $item['name'] ?? null,
                            'content_type' => $item['content_type'] ?? $item['mime_type'] ?? null,
                            'size' => $item['size'] ?? null,
                            'content' => '[redacted]',
                        ];
                    }, $value);

                    continue;
                }

                $out[$keyStr] = $this->sanitize($value);
                continue;
            }

            $out[$keyStr] = $value;
        }

        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > 60000) {
            return ['_truncated' => true, 'preview' => substr($json, 0, 2000)];
        }

        return $out;
    }
}
