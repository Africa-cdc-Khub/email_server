<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreExternalIntegrationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateExternalIntegrationRequest;
use App\Models\ExternalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ExternalIntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        $integrations = ExternalIntegration::query()
            ->with('emailProvider:id,name,driver')
            ->orderBy('name')
            ->get()
            ->map(fn (ExternalIntegration $integration) => $this->transform($integration));

        return response()->json(['data' => $integrations]);
    }

    public function store(StoreExternalIntegrationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $clientSecret = $this->resolveClientSecret($data);
        $slug = $data['slug'] ?? $data['client_id'] ?? Str::slug($data['name']);

        $integration = ExternalIntegration::query()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'api_key_hash' => ExternalIntegration::hashClientSecret($clientSecret),
            'api_key_prefix' => ExternalIntegration::clientSecretHint($clientSecret),
            'email_provider_id' => $data['email_provider_id'] ?? null,
            'allowed_ips' => $data['allowed_ips'] ?? [],
            'settings' => $data['settings'] ?? [],
            'is_active' => $data['is_active'] ?? true,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json([
            'data' => $this->transform($integration->load('emailProvider:id,name,driver')),
            'message' => 'Integration created. Share client_id and client_secret with the connecting system.',
            'client_secret' => $clientSecret,
        ], 201);
    }

    public function show(ExternalIntegration $externalIntegration): JsonResponse
    {
        $externalIntegration->load('emailProvider:id,name,driver');

        return response()->json(['data' => $this->transform($externalIntegration)]);
    }

    public function update(UpdateExternalIntegrationRequest $request, ExternalIntegration $externalIntegration): JsonResponse
    {
        $data = $request->validated();
        $clientSecret = null;

        if (($data['generate_secret'] ?? false) || ! empty($data['client_secret'])) {
            $clientSecret = $this->resolveClientSecret($data);
            $data['api_key_hash'] = ExternalIntegration::hashClientSecret($clientSecret);
            $data['api_key_prefix'] = ExternalIntegration::clientSecretHint($clientSecret);
        }

        unset($data['client_secret'], $data['generate_secret']);

        $externalIntegration->update($data);

        $response = [
            'data' => $this->transform($externalIntegration->fresh()->load('emailProvider:id,name,driver')),
        ];

        if ($clientSecret !== null) {
            $response['client_secret'] = $clientSecret;
            $response['message'] = 'Integration updated. Share the new client_secret with the connecting system.';
        }

        return response()->json($response);
    }

    public function destroy(ExternalIntegration $externalIntegration): JsonResponse
    {
        $externalIntegration->delete();

        return response()->json(['message' => 'Integration deleted.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveClientSecret(array $data): string
    {
        if (($data['generate_secret'] ?? false) || empty($data['client_secret'])) {
            return ExternalIntegration::generateClientSecret();
        }

        return (string) $data['client_secret'];
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(ExternalIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'slug' => $integration->slug,
            'client_id' => $integration->slug,
            'client_secret_hint' => $integration->api_key_prefix,
            'email_provider_id' => $integration->email_provider_id,
            'email_provider' => $integration->emailProvider ? [
                'id' => $integration->emailProvider->id,
                'name' => $integration->emailProvider->name,
                'driver' => $integration->emailProvider->driver->value,
            ] : null,
            'allowed_ips' => $integration->allowed_ips ?? [],
            'settings' => $integration->settings ?? [],
            'is_active' => $integration->is_active,
            'last_used_at' => $integration->last_used_at,
            'description' => $integration->description,
            'created_at' => $integration->created_at,
            'updated_at' => $integration->updated_at,
        ];
    }
}
