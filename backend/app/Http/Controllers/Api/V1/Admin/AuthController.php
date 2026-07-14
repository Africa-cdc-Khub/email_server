<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Admin\LoginRequest;
use App\Http\Requests\Api\V1\Admin\ResetPasswordRequest;
use App\Models\User;
use App\Services\AdminPasswordResetService;
use App\Services\AdminTwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        AdminTwoFactorService $twoFactor,
    ): JsonResponse {
        try {
            $user = User::query()->where('email', $request->validated('email'))->first();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Database unavailable. Check MySQL credentials and run migrate/seed.',
            ], 503);
        }

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            return response()->json($twoFactor->beginLoginChallenge($user));
        }

        $token = $user->createToken('admin-panel')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->transformUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->transformUser($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request, AdminPasswordResetService $passwordReset): JsonResponse
    {
        $passwordReset->sendResetLink($request->validated('email'));

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request, AdminPasswordResetService $passwordReset): JsonResponse
    {
        $passwordReset->resetPassword(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return response()->json([
            'message' => 'Password updated. You can sign in with your new password.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'is_active' => (bool) $user->is_active,
            'two_factor_email_enabled' => (bool) $user->two_factor_email_enabled,
            'two_factor_totp_enabled' => (bool) $user->two_factor_totp_enabled,
        ];
    }
}
