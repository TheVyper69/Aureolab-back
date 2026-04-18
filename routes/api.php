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
use App\Http\Controllers\Api\LensTypeController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\BoxController;
use App\Http\Controllers\Api\ReportsController;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::post('/auth/login', [AuthController::class, 'login']);

// Solo pruebas locales. Quita esto en producción.
Route::get('/php-limits', function () {
    return response()->json([
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Protected routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated user
    |--------------------------------------------------------------------------
    */

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return response()->json([
            'ok' => true,
            'user' => $request->user(),
            'role' => $request->user()?->role?->name,
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Admin / Employee / Optica
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:admin,employee,optica')->group(function () {

        // Orders
        Route::get('/orders', [OrdersController::class, 'index']);
        Route::post('/orders', [OrdersController::class, 'store']);
        Route::get('/orders/{id}', [OrdersController::class, 'show']);
        Route::patch('/orders/{id}/cancel', [OrdersController::class, 'cancel']);

        // Products / Inventory / Categories
        Route::get('/products', [ProductsController::class, 'index']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('/categories', [CategoriesController::class, 'index']);

        // Product images / treatments
        Route::get('/products/{id}/image', [ProductsController::class, 'image']);
        Route::get('/treatments', [TreatmentsController::class, 'index']);
        Route::get('/products/{id}/treatments', [TreatmentsController::class, 'byProduct']);

        // Legacy sales
        Route::post('/sales', [SalesController::class, 'store']);
        Route::get('/sales/{id}', [SalesController::class, 'show']);
        Route::get('/sales', function () {
            return response()->json([
                'ok' => false,
                'message' => 'Use POST /api/sales to create a sale.'
            ], 405);
        });

        // Catalogs
        Route::get('/opticas', [OpticasController::class, 'index']);
        Route::get('/lens-types', [LensTypeController::class, 'index']);
        Route::get('/materials', [MaterialController::class, 'index']);
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/boxes', [BoxController::class, 'index']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/dashboard', [ReportsController::class, 'dashboard']);
            Route::get('/orders/by-day', [ReportsController::class, 'ordersByDay']);
            Route::get('/orders/payment-methods', [ReportsController::class, 'ordersPaymentMethods']);
            Route::get('/orders/top-products', [ReportsController::class, 'ordersTopProducts']);
        });

        Route::patch('/orders/{id}', [OrdersController::class, 'update']);
    });

    /*
    |--------------------------------------------------------------------------
    | Admin only
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:admin')->group(function () {

        // Auth
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::get('/users', [AuthController::class, 'usersIndex']);
        Route::put('/users/{id}', [AuthController::class, 'updateUser']);
        Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);

        // Opticas
        Route::post('/opticas', [OpticasController::class, 'store']);

        // Products
        Route::post('/products', [ProductsController::class, 'store']);
        Route::get('/products/{id}', [ProductsController::class, 'show']);
        Route::put('/products/{id}', [ProductsController::class, 'update']);
        Route::delete('/products/{id}', [ProductsController::class, 'destroy']);
        Route::post('/products/{id}/stock', [ProductsController::class, 'addStock']);

        // Categories
        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::put('/categories/{id}', [CategoriesController::class, 'update']);
        Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    });
});