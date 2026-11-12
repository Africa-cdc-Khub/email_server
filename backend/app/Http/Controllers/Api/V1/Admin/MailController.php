<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SendMailRequest;
use App\Models\EmailLog;
use App\Services\EmailAttachmentService;
use App\Services\EmailDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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

    /**
     * List pending admin-send subjects with counts (Queue control tab).
     */
    public function pendingSubjects(EmailDispatchService $dispatch): JsonResponse
    {
        return response()->json([
            'data' => $dispatch->pendingSubjects(50),
        ]);
    }

    /**
     * Preview pending + paused counts for an exact subject.
     */
    public function queueBySubject(Request $request, EmailDispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
        ]);

        $subject = trim($data['subject']);

        return response()->json([
            'subject' => $subject,
            'pending_count' => $dispatch->countPendingBySubject($subject),
            'paused_count' => $dispatch->countPausedBySubject($subject),
        ]);
    }

    /**
     * Fail all pending emails with this exact subject (keep body for later retry).
     */
    public function pauseBySubject(Request $request, EmailDispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $dispatch->pausePendingBySubject(
                $data['subject'],
                (string) ($data['reason'] ?? 'Paused by admin (busy hours)'),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subject' => [$e->getMessage()]]);
        }

        $paused = $result['paused'];

        return response()->json([
            'message' => $paused === 0
                ? 'No pending emails found for that subject.'
                : "Paused {$paused} pending email(s). They can be sent later from Queue control.",
            'paused' => $paused,
            'subject' => trim($data['subject']),
        ]);
    }

    /**
     * Re-queue admin-paused emails for a subject, optionally at send_at.
     */
    public function scheduleRetryBySubject(Request $request, EmailDispatchService $dispatch): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'send_at' => ['sometimes', 'nullable', 'date', 'after:now', 'before:'.now()->addDays(30)->toDateTimeString()],
        ]);

        $sendAtRaw = $data['send_at'] ?? null;
        $sendAt = is_string($sendAtRaw) && $sendAtRaw !== ''
            ? Carbon::parse($sendAtRaw)
            : null;

        try {
            $result = $dispatch->scheduleRetryPausedBySubject($data['subject'], $sendAt);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['subject' => [$e->getMessage()]]);
        }

        $queued = $result['queued'];
        $skipped = $result['skipped'];
        $scheduled = $sendAt !== null;

        if ($queued === 0) {
            return response()->json([
                'message' => 'No paused emails found for that subject to retry.',
                'queued' => 0,
                'skipped' => $skipped,
                'scheduled' => $scheduled,
                'send_at' => $sendAt?->toIso8601String(),
            ]);
        }

        return response()->json([
            'message' => $scheduled
                ? "Scheduled {$queued} paused email(s) for later delivery."
                    .($skipped > 0 ? " Skipped {$skipped} without a stored body." : '')
                : "Queued {$queued} paused email(s) for send now."
                    .($skipped > 0 ? " Skipped {$skipped} without a stored body." : ''),
            'queued' => $queued,
            'skipped' => $skipped,
            'scheduled' => $scheduled,
            'send_at' => $sendAt?->toIso8601String(),
            'subject' => trim($data['subject']),
        ]);
    }
}
