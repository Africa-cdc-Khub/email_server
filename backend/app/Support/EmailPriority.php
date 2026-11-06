<?php

namespace App\Support;

use App\Models\ExternalIntegration;

final class EmailPriority
{
    public const QUEUE_DEFAULT = 'emails';

    public const QUEUE_PRIORITY = 'emails-priority';

    /**
     * Resolve queue for a send: token/OTP clients always use priority;
     * otherwise OTP-like subjects are elevated.
     */
    public static function queueFor(string $subject, ?ExternalIntegration $integration = null): string
    {
        if ($integration?->prioritizesTokens()) {
            return self::QUEUE_PRIORITY;
        }

        return self::queueForSubject($subject);
    }

    /**
     * OTP / sign-in / verification subjects go to the priority Redis queue
     * so login codes are not stuck behind bulk registration or reminder mail.
     */
    public static function queueForSubject(string $subject): string
    {
        if (preg_match(
            '/sign-?in code|verification code|\botp\b|one[-\s]?time (?:pass(?:word|code)|code)|login code|auth(?:entication)? code/i',
            $subject
        ) === 1) {
            return self::QUEUE_PRIORITY;
        }

        return self::QUEUE_DEFAULT;
    }
}
