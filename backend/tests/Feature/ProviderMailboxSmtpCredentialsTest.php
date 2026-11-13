<?php

namespace Tests\Feature;

use App\Enums\EmailDriver;
use App\Models\EmailProvider;
use App\Models\User;
use App\Services\PhpMailerSmtpMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderMailboxSmtpCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_mailbox_requires_username_and_password(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/admin/email-providers', [
            'name' => 'MS Communications',
            'driver' => EmailDriver::Smtp->value,
            'from_address' => 'noreply@africacdc.info',
            'is_default' => true,
            'is_active' => true,
            'config' => [
                'host' => 'smtp.hostinger.com',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'mailboxes' => [
                [
                    'email' => 'noreply@africacdc.info',
                    'is_active' => true,
                    'daily_quota' => 10000,
                    'hourly_quota' => 400,
                    'weight' => 1,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'mailboxes.0.smtp_username',
                'mailboxes.0.smtp_password',
            ]);
    }

    public function test_smtp_mailbox_credentials_are_stored_and_used_for_send_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/v1/admin/email-providers', [
            'name' => 'MS Communications',
            'driver' => EmailDriver::Smtp->value,
            'from_address' => 'noreply@africacdc.info',
            'is_default' => true,
            'is_active' => true,
            'config' => [
                'host' => 'smtp.hostinger.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'provider-user',
                'password' => 'provider-pass',
            ],
            'mailboxes' => [
                [
                    'email' => 'noreply@africacdc.info',
                    'smtp_username' => 'noreply@africacdc.info',
                    'smtp_password' => 'mailbox-secret',
                    'is_active' => true,
                    'daily_quota' => 10000,
                    'hourly_quota' => 400,
                    'weight' => 1,
                ],
            ],
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertTrue((bool) $create->json('data.mailboxes.0.has_smtp_password'));
        $this->assertSame('noreply@africacdc.info', $create->json('data.mailboxes.0.smtp_username'));
        $this->assertArrayNotHasKey('smtp_password', $create->json('data.mailboxes.0'));

        $provider = EmailProvider::query()->with('mailboxes')->findOrFail($id);
        $mailbox = $provider->mailboxes->firstOrFail();

        $settings = app(PhpMailerSmtpMailer::class)->resolveSettings(
            $provider,
            'noreply@africacdc.info',
            $mailbox,
        );

        $this->assertSame('noreply@africacdc.info', $settings['username']);
        $this->assertSame('mailbox-secret', $settings['password']);

        // Blank password on update keeps the stored secret.
        $this->withToken($token)->putJson("/api/v1/admin/email-providers/{$id}", [
            'name' => 'MS Communications',
            'driver' => EmailDriver::Smtp->value,
            'from_address' => 'noreply@africacdc.info',
            'is_active' => true,
            'is_default' => true,
            'priority' => 10,
            'config' => [
                'host' => 'smtp.hostinger.com',
                'port' => 465,
                'encryption' => 'ssl',
            ],
            'mailboxes' => [
                [
                    'id' => $mailbox->id,
                    'email' => 'noreply@africacdc.info',
                    'smtp_username' => 'noreply@africacdc.info',
                    'is_active' => true,
                    'daily_quota' => 100000,
                    'hourly_quota' => 400,
                    'weight' => 1,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.mailboxes.0.has_smtp_password', true);

        $this->assertSame(
            'mailbox-secret',
            $mailbox->fresh()->smtp_password
        );
    }
}
