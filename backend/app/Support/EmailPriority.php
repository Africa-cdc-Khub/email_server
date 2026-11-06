<?php

namespace App\Support;

final class EmailPriority
{
    public const QUEUE_DEFAULT = 'emails';

    public const QUEUE_PRIORITY = 'emails-priority';

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
