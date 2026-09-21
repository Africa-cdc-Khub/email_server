<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Jobs\SendEmailJob;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Services\EmailDispatchService;
use App\Services\PhpMailerSmtpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmailProviderFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_falls_back_to_next_active_provider_when_default_fails(): void
    {
        $primary = EmailProvider::query()->create([
            'name' => 'Default Exchange (simulated)',
            'slug' => 'default-exchange',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'broken.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'secret',
            ],
            'from_address' => 'notifications@example.com',
            'from_name' => 'Mailer',
            'is_default' => true,
            'is_active' => true,
            'priority' => 10,
        ]);

        $fallback = EmailProvider::query()->create([
            'name' => 'SMTP Fallback',
            'slug' => 'fallback-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'secret',
            ],
            'from_address' => 'notifications@example.com',
            'from_name' => 'Mailer',
            'is_default' => false,
            'is_active' => true,
            'priority' => 100,
        ]);

        $smtpMock = Mockery::mock(PhpMailerSmtpMailer::class);
        $smtpMock->shouldReceive('send')
            ->once()
            ->withArgs(fn (EmailProvider $p) => $p->id === $primary->id)
            ->andThrow(new RuntimeException('Primary provider unavailable'));
        $smtpMock->shouldReceive('send')
            ->once()
            ->withArgs(fn (EmailProvider $p) => $p->id === $fallback->id)
            ->andReturnNull();
        $this->app->instance(PhpMailerSmtpMailer::class, $smtpMock);

        $log = EmailLog::query()->create([
            'email_provider_id' => $primary->id,
            'to' => 'recipient@example.com',
            'subject' => 'Fallback test',
            'status' => 'pending',
            'driver' => EmailDriver::Smtp->value,
            'meta' => [
                'body' => '<p>Hello</p>',
                'is_html' => true,
                'cc' => [],
                'bcc' => [],
                'source' => 'admin',
            ],
        ]);

        $job = new SendEmailJob($log->id);
        $job->handle(app(EmailDispatchService::class));

        $fresh = $log->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame($fallback->id, $fresh->email_provider_id);
        $this->assertSame(EmailDriver::Smtp->value, $fresh->driver);
        $this->assertSame($primary->id, $fresh->meta['fallback_from_provider_id'] ?? null);
        $this->assertStringContainsString('Primary provider unavailable', (string) ($fresh->meta['fallback_error'] ?? ''));
    }

    public function test_inactive_providers_are_not_used_as_fallback(): void
    {
        $primary = EmailProvider::query()->create([
            'name' => 'Default',
            'slug' => 'default-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'broken.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'secret',
            ],
            'from_address' => 'notifications@example.com',
            'is_default' => true,
            'is_active' => true,
            'priority' => 10,
        ]);

        EmailProvider::query()->create([
            'name' => 'Disabled SMTP',
            'slug' => 'disabled-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'secret',
            ],
            'from_address' => 'notifications@example.com',
            'is_default' => false,
            'is_active' => false,
            'priority' => 50,
        ]);

        $smtpMock = Mockery::mock(PhpMailerSmtpMailer::class);
        $smtpMock->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Primary provider unavailable'));
        $this->app->instance(PhpMailerSmtpMailer::class, $smtpMock);

        $log = EmailLog::query()->create([
            'email_provider_id' => $primary->id,
            'to' => 'recipient@example.com',
            'subject' => 'No fallback',
            'status' => 'pending',
            'driver' => EmailDriver::Smtp->value,
            'meta' => [
                'body' => '<p>Hello</p>',
                'is_html' => true,
                'cc' => [],
                'bcc' => [],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Primary provider unavailable');

        $job = new SendEmailJob($log->id);
        $job->handle(app(EmailDispatchService::class));
    }

    public function test_provider_test_does_not_fall_back_to_another_provider(): void
    {
        $primary = EmailProvider::query()->create([
            'name' => 'Default',
            'slug' => 'default-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'broken.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'secret',
            ],
            'from_address' => 'notifications@example.com',
            'is_default' => true,
            'is_active' => true,
            'priority' => 10,
        ]);

        EmailProvider::query()->create([
            'name' => 'SMTP Fallback',
            'slug' => 'fallback-smtp',
            'driver' => EmailDriver::Smtp,
            'config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'secret',
            ],
            'from_address' => 'notifications@example.com',
            'is_default' => false,
            'is_active' => true,
            'priority' => 100,
        ]);

        $smtpMock = Mockery::mock(PhpMailerSmtpMailer::class);
        $smtpMock->shouldReceive('send')
            ->once()
            ->withArgs(fn (EmailProvider $p) => $p->id === $primary->id)
            ->andThrow(new RuntimeException('Primary provider unavailable'));
        $smtpMock->shouldNotReceive('send')->withArgs(fn (EmailProvider $p) => $p->id !== $primary->id);
        $this->app->instance(PhpMailerSmtpMailer::class, $smtpMock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Primary provider unavailable');

        app(EmailDispatchService::class)->testProvider($primary, 'admin@example.com');
    }
}
