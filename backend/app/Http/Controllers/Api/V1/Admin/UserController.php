<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with(['externalIntegrations:id,name,slug'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->transform($user));

        return response()->json(['data' => $users]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_admin' => $data['is_admin'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->syncIntegrations($user, $data);

        return response()->json([
            'data' => $this->transform($user->fresh()->load(['externalIntegrations:id,name,slug'])),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['externalIntegrations:id,name,slug']);

        return response()->json(['data' => $this->transform($user)]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        $integrationIdsProvided = array_key_exists('external_integration_ids', $data);
        $integrationIds = $data['external_integration_ids'] ?? null;
        unset($data['external_integration_ids']);

        $user->update($data);

        if ($integrationIdsProvided || array_key_exists('is_admin', $data)) {
            $this->syncIntegrations($user, [
                'is_admin' => $user->is_admin,
                'external_integration_ids' => $integrationIdsProvided
                    ? ($integrationIds ?? [])
                    : $user->externalIntegrations()->pluck('external_integrations.id')->all(),
            ]);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => $this->transform($user->fresh()->load(['externalIntegrations:id,name,slug'])),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->is_admin && User::query()->where('is_admin', true)->where('is_active', true)->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last active admin.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncIntegrations(User $user, array $data): void
    {
        if (($data['is_admin'] ?? $user->is_admin) === true) {
            $user->externalIntegrations()->sync([]);

            return;
        }

        if (! array_key_exists('external_integration_ids', $data)) {
            return;
        }

        $ids = collect($data['external_integration_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $user->externalIntegrations()->sync($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(User $user): array
    {
        $integrations = $user->relationLoaded('externalIntegrations')
            ? $user->externalIntegrations
            : $user->externalIntegrations()->get(['external_integrations.id', 'name', 'slug']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'is_active' => (bool) $user->is_active,
            'external_integration_ids' => $integrations->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'external_integrations' => $integrations->map(fn ($i) => [
                'id' => (int) $i->id,
                'name' => $i->name,
                'slug' => $i->slug,
            ])->values()->all(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
