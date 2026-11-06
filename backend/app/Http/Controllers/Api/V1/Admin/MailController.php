<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SendMailRequest;
use App\Models\EmailLog;
use App\Services\EmailAttachmentService;
use App\Services\EmailDispatchService;
use Illuminate\Http\JsonResponse;
use Throwable;

class MailController extends Controller
{
    public function send(
        SendMailRequest $request,
        EmailDispatchService $dispatch,
        EmailAttachmentService $attachments,
    ): JsonResponse {
        $attachmentPayload = $attachments->normalizeFromRequest($request->input('attachments'));
        /** @var list<string> $recipients */
        $recipients = $request->validated('to');
        $subject = $request->validated('subject');
        $body = $request->validated('body');
        $isHtml = $request->boolean('is_html', true);
        $providerId = $request->validated('provider_id');
        $cc = $request->validated('cc') ?? [];
        $bcc = $request->validated('bcc') ?? [];

        $logs = [];

        try {
            foreach ($recipients as $to) {
                $logs[] = $dispatch->queue(
                    to: $to,
                    subject: $subject,
                    body: $body,
                    isHtml: $isHtml,
                    providerId: $providerId,
                    integration: null,
                    cc: $cc,
                    bcc: $bcc,
                    source: 'admin',
                    senderIp: $request->ip(),
                    attachmentPayload: $attachmentPayload,
                );
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Failed to queue email.',
            ], 422);
        }

        /** @var EmailLog $first */
        $first = $logs[0];
        $count = count($logs);

        return response()->json([
            'message' => $count === 1
                ? 'Email accepted and queued for delivery.'
                : "{$count} emails accepted and queued for delivery.",
            'log_id' => $first->id,
            'log_ids' => array_map(static fn (EmailLog $log) => $log->id, $logs),
            'queued_count' => $count,
            'status' => $first->status,
            'attachment_count' => (int) (($first->meta['attachment_count'] ?? 0)),
        ]);
    }
}
