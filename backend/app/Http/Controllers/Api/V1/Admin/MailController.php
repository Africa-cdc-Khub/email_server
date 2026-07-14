<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SendMailRequest;
use App\Services\EmailDispatchService;
use Illuminate\Http\JsonResponse;

class MailController extends Controller
{
    public function send(SendMailRequest $request, EmailDispatchService $dispatch): JsonResponse
    {
        $log = $dispatch->queue(
            to: $request->validated('to'),
            subject: $request->validated('subject'),
            body: $request->validated('body'),
            isHtml: $request->boolean('is_html', true),
            providerId: $request->validated('provider_id'),
            integration: null,
            cc: $request->validated('cc') ?? [],
            bcc: $request->validated('bcc') ?? [],
            source: 'admin',
        );

        return response()->json([
            'message' => 'Email accepted and queued for delivery.',
            'log_id' => $log->id,
            'status' => $log->status,
        ]);
    }
}
