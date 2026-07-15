<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', HealthController::class);

if (config('app.api_docs_enabled')) {
    Route::redirect('/', '/api/documentation');
} else {
    Route::redirect('/', '/api/health');
}
