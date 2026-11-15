<?php

use App\Http\Controllers\Api\V1\HealthCheckController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Phase 2 Health Check
    Route::get('/health', HealthCheckController::class);

    // Step 2: Catalog & Inventory Read APIs
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);

    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::get('/warehouses/{id}', [WarehouseController::class, 'show']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/product/{productId}', [InventoryController::class, 'showByProduct']);
    Route::post('/inventory/adjustments', [InventoryController::class, 'adjust']);

    // Step 5: Reservation Endpoints
    Route::post('/reservations/pessimistic', [ReservationController::class, 'storePessimistic']);
    Route::get('/reservations/{id}', [ReservationController::class, 'show']);
});
