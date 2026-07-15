<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EmailDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreEmailProviderRequest;
use App\Http\Requests\Api\V1\Admin\TestEmailProviderRequest;
use App\Http\Requests\Api\V1\Admin\UpdateEmailProviderRequest;
use App\Models\EmailProvider;
use App\Services\EmailDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = EmailProvider::query()
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(fn (EmailProvider $provider) => $this->transform($provider));

        return response()->json(['data' => $providers]);
    }

    public function drivers(): JsonResponse
    {
        $drivers = collect(EmailDriver::cases())->map(fn (EmailDriver $driver) => [
            'value' => $driver->value,
            'label' => $driver->label(),
            'fields' => $this->driverFields($driver),
        ]);

        return response()->json(['data' => $drivers]);
    }

    public function store(StoreEmailProviderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null, $data['name']);
        $data['config'] = $data['config'] ?? [];

        if (! empty($data['is_default'])) {
            EmailProvider::query()->update(['is_default' => false]);
        }

        try {
            $provider = EmailProvider::query()->create($data);
        } catch (\Illuminate\Encryption\MissingAppKeyException $e) {
            report($e);

            return response()->json([
                'message' => 'Server misconfiguration: APP_KEY is missing. Providers store encrypted credentials and cannot be saved until APP_KEY is set in backend/.env, then recreate the app container.',
            ], 500);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'No application encryption key')) {
                report($e);

                return response()->json([
                    'message' => 'Server misconfiguration: APP_KEY is missing. Run deploy/fix-app-key.sh on the server.',
                ], 500);
            }
            throw $e;
        }

        return response()->json(['data' => $this->transform($provider)], 201);
    }

    public function show(EmailProvider $emailProvider): JsonResponse
    {
        return response()->json(['data' => $this->transform($emailProvider, revealSecrets: true)]);
    }

    public function update(UpdateEmailProviderRequest $request, EmailProvider $emailProvider): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['is_default'])) {
            EmailProvider::query()->where('id', '!=', $emailProvider->id)->update(['is_default' => false]);
        }

        if (isset($data['config']) && is_array($data['config'])) {
            $data['config'] = array_merge($emailProvider->config ?? [], array_filter(
                $data['config'],
                fn ($value) => $value !== null && $value !== ''
            ));
        }

        $emailProvider->update($data);

        return response()->json(['data' => $this->transform($emailProvider->fresh(), revealSecrets: true)]);
    }

    public function destroy(EmailProvider $emailProvider): JsonResponse
    {
        if ($emailProvider->is_default) {
            return response()->json([
                'message' => 'Cannot delete the default provider. Set another provider as default first.',
            ], 422);
        }

        $emailProvider->delete();

        return response()->json(['message' => 'Provider deleted.']);
    }

    public function test(TestEmailProviderRequest $request, EmailProvider $emailProvider, EmailDispatchService $dispatch): JsonResponse
    {
        $log = $dispatch->testProvider($emailProvider, $request->validated('to'));

        return response()->json([
            'message' => 'Test email sent.',
            'log' => $log,
        ]);
    }

    public function setDefault(EmailProvider $emailProvider): JsonResponse
    {
        EmailProvider::query()->update(['is_default' => false]);
        $emailProvider->update(['is_default' => true, 'is_active' => true]);

        return response()->json(['data' => $this->transform($emailProvider->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(EmailProvider $provider, bool $revealSecrets = false): array
    {
        $config = $provider->config ?? [];

        if (! $revealSecrets) {
            $config = $this->maskSecrets($config);
        }

        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'slug' => $provider->slug,
            'driver' => $provider->driver->value,
            'driver_label' => $provider->driver->label(),
            'config' => $config,
            'from_address' => $provider->from_address,
            'from_name' => $provider->from_name,
            'is_default' => $provider->is_default,
            'is_active' => $provider->is_active,
            'priority' => $provider->priority,
            'description' => $provider->description,
            'created_at' => $provider->created_at,
            'updated_at' => $provider->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function maskSecrets(array $config): array
    {
        foreach (['client_secret', 'password'] as $key) {
            if (! empty($config[$key])) {
                $config[$key] = '********';
            }
        }

        return $config;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function driverFields(EmailDriver $driver): array
    {
        return match ($driver) {
            EmailDriver::Exchange => [
                ['key' => 'tenant_id', 'label' => 'Tenant ID', 'type' => 'text', 'required' => true],
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
                ['key' => 'redirect_uri', 'label' => 'Redirect URI', 'type' => 'text', 'required' => false],
                ['key' => 'scope', 'label' => 'Scope', 'type' => 'text', 'required' => false, 'default' => 'https://graph.microsoft.com/.default'],
                ['key' => 'auth_method', 'label' => 'Auth method', 'type' => 'select', 'required' => false, 'options' => [
                    ['value' => 'client_credentials', 'label' => 'Client credentials'],
                    ['value' => 'authorization_code', 'label' => 'Authorization code'],
                ]],
            ],
            EmailDriver::Smtp => [
                ['key' => 'host', 'label' => 'SMTP host', 'type' => 'text', 'required' => true],
                ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'default' => 587],
                ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'required' => false, 'options' => [
                    ['value' => 'tls', 'label' => 'TLS'],
                    ['value' => 'ssl', 'label' => 'SSL'],
                    ['value' => '', 'label' => 'None'],
                ]],
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => false],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => false],
            ],
            EmailDriver::Ses => [
                ['key' => 'key', 'label' => 'AWS access key', 'type' => 'text', 'required' => false],
                ['key' => 'secret', 'label' => 'AWS secret', 'type' => 'password', 'required' => false],
                ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'required' => false, 'default' => 'us-east-1'],
            ],
            EmailDriver::Log => [],
        };
    }

    private function uniqueSlug(?string $slug, string $name): string
    {
        $base = Str::slug($slug ?: $name) ?: 'provider';
        $candidate = $base;
        $i = 1;
        while (EmailProvider::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
