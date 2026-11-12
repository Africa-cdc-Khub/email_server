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
        // Large scheduled batches can take a while to enqueue.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $attachmentPayload = $attachments->normalizeFromRequest($request->input('attachments'));
        /** @var list<string> $recipients */
        $recipients = $request->validated('to');
        $subject = $request->validated('subject');
        $body = $request->validated('body');
        $isHtml = $request->boolean('is_html', true);
        $providerId = $request->validated('provider_id');
        $cc = $request->validated('cc') ?? [];
        $bcc = $request->validated('bcc') ?? [];
        $sendAtRaw = $request->validated('send_at');
        $sendAt = is_string($sendAtRaw) && $sendAtRaw !== ''
            ? \Illuminate\Support\Carbon::parse($sendAtRaw)
            : null;

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
                    sendAt: $sendAt,
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
        $scheduled = $sendAt !== null;

        return response()->json([
            'message' => $scheduled
                ? ($count === 1
                    ? 'Email scheduled for delivery.'
                    : "{$count} emails scheduled for delivery.")
                : ($count === 1
                    ? 'Email accepted and queued for delivery.'
                    : "{$count} emails accepted and queued for delivery."),
            'log_id' => $first->id,
            'log_ids' => array_map(static fn (EmailLog $log) => $log->id, $logs),
            'queued_count' => $count,
            'scheduled' => $scheduled,
            'send_at' => $sendAt?->toIso8601String(),
            'status' => $first->status,
            'attachment_count' => (int) (($first->meta['attachment_count'] ?? 0)),
        ]);
    }
}
