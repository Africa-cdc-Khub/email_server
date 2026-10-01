<?php

namespace Tests\Unit;

use App\Support\EmailPriority;
use PHPUnit\Framework\TestCase;

class EmailPriorityTest extends TestCase
{
    public function test_sign_in_code_subjects_use_priority_queue(): void
    {
        $this->assertSame(
            EmailPriority::QUEUE_PRIORITY,
            EmailPriority::queueForSubject('Your sign-in code: 123456'),
        );
        $this->assertSame(
            EmailPriority::QUEUE_PRIORITY,
            EmailPriority::queueForSubject('Verification code for Africa CDC'),
        );
        $this->assertSame(
            EmailPriority::QUEUE_PRIORITY,
            EmailPriority::queueForSubject('SIGN-IN CODE: ABCD'),
        );
    }

    public function test_normal_subjects_use_default_queue(): void
    {
        $this->assertSame(
            EmailPriority::QUEUE_DEFAULT,
            EmailPriority::queueForSubject('Weekly newsletter'),
        );
        $this->assertSame(
            EmailPriority::QUEUE_DEFAULT,
            EmailPriority::queueForSubject('Meeting invite'),
        );
    }
}
