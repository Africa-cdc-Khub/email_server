<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/health', HealthController::class);

// Frontend SPA owns `/` on the public host; API root stays a health redirect.
Route::redirect('/', '/api/v1/health');
