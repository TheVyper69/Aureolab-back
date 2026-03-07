<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ProductsController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\OpticasController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\TreatmentsController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:admin,employee,optica')->group(function () {
        Route::get('/orders', [OrdersController::class, 'index']);
        Route::post('/orders', [OrdersController::class, 'store']);
        Route::get('/orders/{id}', [OrdersController::class, 'show']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/opticas', [OpticasController::class, 'store']);
    Route::get('/opticas', [OpticasController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return response()->json([
            'ok' => true,
            'user' => $request->user(),
            'role' => $request->user()?->role?->name,
        ]);
    });

    Route::middleware('role:admin,employee,optica')->group(function () {
        Route::get('/products', [ProductsController::class, 'index']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/categories', [CategoriesController::class, 'index']);

        // NUEVO: tratamientos
        Route::get('/treatments', [TreatmentsController::class, 'index']);
        Route::get('/products/{id}/treatments', [TreatmentsController::class, 'byProduct']);

        Route::get('/products/{id}/image', [ProductsController::class, 'image']);

        Route::post('/sales', [SalesController::class, 'store']);
        Route::get('/sales/{id}', [SalesController::class, 'show']);

        Route::get('/sales', function () {
            return response()->json([
                'ok' => false,
                'message' => 'Use POST /api/sales to create a sale.'
            ], 405);
        });

        Route::get('/opticas', [OpticasController::class, 'index']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->post('/auth/register', [AuthController::class, 'register']);

    Route::middleware('role:admin')->group(function () {
        Route::post('/products', [ProductsController::class, 'store']);
        Route::get('/products/{id}', [ProductsController::class, 'show']);
        Route::put('/products/{id}', [ProductsController::class, 'update']);
        Route::delete('/products/{id}', [ProductsController::class, 'destroy']);
        Route::post('/products/{id}/stock', [ProductsController::class, 'addStock']);

        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::put('/categories/{id}', [CategoriesController::class, 'update']);
        Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);
    });

    Route::patch('/orders/{id}/cancel', [OrdersController::class, 'cancel']);
});