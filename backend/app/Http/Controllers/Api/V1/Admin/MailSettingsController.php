<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->payload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mail_pending_retry_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
        ]);

        SystemSetting::setValue(
            SystemSetting::MAIL_PENDING_RETRY_SECONDS,
            $validated['mail_pending_retry_seconds'],
        );

        return response()->json([
            'message' => 'Mail settings saved.',
            'data' => $this->payload(),
        ]);
    }

    /**
     * @return array{mail_pending_retry_seconds: int}
     */
    private function payload(): array
    {
        return [
            'mail_pending_retry_seconds' => SystemSetting::mailPendingRetrySeconds(),
        ];
    }
}
