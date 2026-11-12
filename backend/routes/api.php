<?php

use App\Http\Controllers\Api\V1\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Phase 2 Health Check
    Route::get('/health', HealthCheckController::class);
});
