<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IntegrationTokenRequest;
use App\Models\ExternalIntegration;
use App\Services\IntegrationJwtService;
use Illuminate\Http\JsonResponse;

class IntegrationAuthController extends Controller
{
    public function token(IntegrationTokenRequest $request, IntegrationJwtService $jwt): JsonResponse
    {
        $integration = $this->resolveIntegration($request->validated('client_id'), $request->validated('client_secret'));

        if (! $integration->allowsIp($request->ip())) {
            return response()->json(['message' => 'IP address not allowed for this integration.'], 403);
        }

        $token = $jwt->issue($integration);

        return response()->json([
            ...$token,
            'integration' => [
                'id' => $integration->id,
                'name' => $integration->name,
                'slug' => $integration->slug,
            ],
        ]);
    }

    private function resolveIntegration(string $clientId, string $clientSecret): ExternalIntegration
    {
        $integration = ExternalIntegration::query()
            ->where('slug', $clientId)
            ->where('is_active', true)
            ->first();

        if ($integration === null || ! $integration->verifyClientSecret($clientSecret)) {
            abort(401, 'Invalid client credentials.');
        }

        return $integration;
    }
}
