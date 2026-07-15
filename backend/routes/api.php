<?php

use App\Http\Controllers\Api\V1\Admin\MailController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BrandingController as AdminBrandingController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\EmailLogController;
use App\Http\Controllers\Api\V1\Admin\EmailProviderController;
use App\Http\Controllers\Api\V1\Admin\ExternalIntegrationController;
use App\Http\Controllers\Api\V1\Admin\TwoFactorController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\IntegrationAuthController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\IntegrationMailController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateIntegrationJwt;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);
    Route::get('/branding', [BrandingController::class, 'show']);
    Route::get('/branding/assets/{path}', [BrandingController::class, 'asset'])
        ->where('path', '.*');

    Route::prefix('admin')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1');
        Route::post('/auth/verify-2fa', [TwoFactorController::class, 'verify'])
            ->middleware('throttle:10,1');
        Route::post('/auth/resend-2fa-email', [TwoFactorController::class, 'resendEmail'])
            ->middleware('throttle:3,1');
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:5,1');
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:10,1');

        Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
            Route::get('/auth/me', [AuthController::class, 'me']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/2fa/status', [TwoFactorController::class, 'status']);
            Route::post('/auth/2fa/email/enable', [TwoFactorController::class, 'enableEmail']);
            Route::post('/auth/2fa/email/disable', [TwoFactorController::class, 'disableEmail']);
            Route::post('/auth/2fa/totp/setup', [TwoFactorController::class, 'setupTotp']);
            Route::post('/auth/2fa/totp/confirm', [TwoFactorController::class, 'confirmTotp']);
            Route::post('/auth/2fa/totp/disable', [TwoFactorController::class, 'disableTotp']);
            Route::get('/dashboard', DashboardController::class);
            Route::get('/email-logs', [EmailLogController::class, 'index']);

            Route::middleware(EnsureUserIsAdmin::class)->group(function () {
                Route::apiResource('users', UserController::class);
                Route::post('/send-mail', [MailController::class, 'send'])
                    ->middleware('throttle:30,1');

                Route::get('/branding', [AdminBrandingController::class, 'show']);
                Route::post('/branding', [AdminBrandingController::class, 'update']);
                Route::put('/branding', [AdminBrandingController::class, 'update']);

                Route::get('/email-providers/drivers', [EmailProviderController::class, 'drivers']);
                Route::post('/email-providers/{email_provider}/test', [EmailProviderController::class, 'test']);
                Route::post('/email-providers/{email_provider}/set-default', [EmailProviderController::class, 'setDefault']);
                Route::apiResource('email-providers', EmailProviderController::class);

                Route::apiResource('external-integrations', ExternalIntegrationController::class);
            });
        });
    });

    Route::post('/integrations/auth/token', [IntegrationAuthController::class, 'token'])
        ->middleware('throttle:30,1');

    Route::middleware(AuthenticateIntegrationJwt::class)->prefix('integrations')->group(function () {
        Route::get('/status', [IntegrationMailController::class, 'status']);
        Route::get('/logs/{logId}', [IntegrationMailController::class, 'showLog'])->whereNumber('logId');
        Route::post('/send', [IntegrationMailController::class, 'send'])->middleware('throttle:60,1');
    });
});
