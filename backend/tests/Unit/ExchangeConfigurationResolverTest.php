<?php

namespace Tests\Unit;

use App\Models\EmailProvider;
use App\Services\ExchangeConfigurationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeConfigurationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_env_values_are_used_before_database_provider_config(): void
    {
        config([
            'exchange-email.tenant_id' => 'env-tenant',
            'exchange-email.client_id' => 'env-client',
            'exchange-email.client_secret' => 'env-secret',
            'mail.from.address' => 'env@example.com',
            'mail.from.name' => 'Env Mailer',
        ]);

        $provider = EmailProvider::factory()->create([
            'config' => [
                'tenant_id' => 'db-tenant',
                'client_id' => 'db-client',
                'client_secret' => 'db-secret',
            ],
            'from_address' => 'db@example.com',
            'from_name' => 'DB Mailer',
        ]);

        $resolver = app(ExchangeConfigurationResolver::class);
        $resolved = $resolver->resolve($provider);

        $this->assertSame('env-tenant', $resolved['tenant_id']);
        $this->assertSame('env-client', $resolved['client_id']);
        $this->assertSame('env-secret', $resolved['client_secret']);
        $this->assertSame('env@example.com', $resolved['from_email']);
        $this->assertSame('Env Mailer', $resolved['from_name']);
    }

    public function test_database_values_are_used_when_env_is_missing(): void
    {
        config([
            'exchange-email.tenant_id' => null,
            'exchange-email.client_id' => null,
            'exchange-email.client_secret' => null,
            'exchange-email.from_email' => null,
            'exchange-email.from_name' => null,
            'mail.from.address' => null,
            'mail.from.name' => null,
        ]);

        $provider = EmailProvider::factory()->create([
            'config' => [
                'tenant_id' => 'db-tenant',
                'client_id' => 'db-client',
                'client_secret' => 'db-secret',
            ],
            'from_address' => 'db@example.com',
            'from_name' => 'DB Mailer',
        ]);

        $resolver = app(ExchangeConfigurationResolver::class);
        $resolved = $resolver->resolve($provider);

        $this->assertSame('db-tenant', $resolved['tenant_id']);
        $this->assertSame('db-client', $resolved['client_id']);
        $this->assertSame('db-secret', $resolved['client_secret']);
        $this->assertSame('db@example.com', $resolved['from_email']);
        $this->assertSame('DB Mailer', $resolved['from_name']);
    }

    public function test_default_from_name_falls_back_to_africa_cdc_mailer(): void
    {
        config([
            'exchange-email.from_email' => null,
            'exchange-email.from_name' => null,
            'mail.from.address' => 'mailer@example.com',
            'mail.from.name' => null,
        ]);

        $provider = EmailProvider::factory()->create([
            'from_address' => null,
            'from_name' => null,
        ]);

        $resolver = app(ExchangeConfigurationResolver::class);

        $this->assertSame('mailer@example.com', $resolver->resolveFromAddress($provider));
        $this->assertSame('Africa CDC Mailer', $resolver->resolveFromName($provider));
    }
}
