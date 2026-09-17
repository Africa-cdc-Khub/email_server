<?php

namespace Tests\Unit;

use App\Enums\EmailDriver;
use App\Models\EmailProvider;
use App\Services\PhpMailerSmtpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PhpMailerSmtpMailerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_non_smtp_providers(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'Exchange',
            'slug' => 'exchange',
            'driver' => EmailDriver::Exchange,
            'config' => [],
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHPMailer SMTP transport requires an SMTP provider.');

        app(PhpMailerSmtpMailer::class)->send(
            provider: $provider,
            to: 'to@example.com',
            subject: 'Test',
            body: 'Hello',
            isHtml: false,
            fromAddress: 'from@example.com',
            fromName: 'From',
        );
    }

    public function test_requires_smtp_host(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'SMTP',
            'slug' => 'smtp',
            'driver' => EmailDriver::Smtp,
            'config' => ['port' => 587],
            'from_address' => 'from@example.com',
            'is_default' => true,
            'is_active' => true,
        ]);

        config()->set('mail.mailers.smtp.host', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP host is not configured');

        app(PhpMailerSmtpMailer::class)->send(
            provider: $provider,
            to: 'to@example.com',
            subject: 'Test',
            body: 'Hello',
            isHtml: false,
            fromAddress: 'from@example.com',
            fromName: 'From',
        );
    }

    public function test_requires_from_address(): void
    {
        $provider = EmailProvider::query()->create([
            'name' => 'SMTP',
            'slug' => 'smtp-from',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('From address is required');

        app(PhpMailerSmtpMailer::class)->send(
            provider: $provider,
            to: 'to@example.com',
            subject: 'Test',
            body: 'Hello',
            isHtml: false,
            fromAddress: '',
            fromName: 'From',
        );
    }
}
