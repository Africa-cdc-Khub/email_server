<?php

namespace App\Support;

final class EmailPriority
{
    public const QUEUE_DEFAULT = 'emails';

    public const QUEUE_PRIORITY = 'emails-priority';

    /**
     * OTP / sign-in verification subjects go to the priority Redis queue.
     */
    public static function queueForSubject(string $subject): string
    {
        if (preg_match('/sign-in code:|verification code/i', $subject) === 1) {
            return self::QUEUE_PRIORITY;
        }

        return self::QUEUE_DEFAULT;
    }
}
