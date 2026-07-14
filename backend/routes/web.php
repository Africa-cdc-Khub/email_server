<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', HealthController::class);

if (! app()->environment('production')) {
    Route::redirect('/', '/api/documentation');
    Route::get('/docs', fn () => redirect('/api/documentation'));
    Route::get('/api/documentation', [ApiDocumentationController::class, 'ui']);
    Route::get('/api/docs.json', [ApiDocumentationController::class, 'spec']);
} else {
    Route::redirect('/', '/api/health');
}
