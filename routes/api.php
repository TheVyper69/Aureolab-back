<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ProductsController;
use App\Http\Controllers\Api\SalesController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return response()->json([
            'ok' => true,
            'user' => $request->user(),
            'role' => $request->user()?->role?->name,
        ]);
    });

    /**
     * ✅ POS / staff (admin, employee, optica) - SOLO LECTURA + ventas
     */
    Route::middleware('role:admin,employee,optica')->group(function () {

        // Catálogo / stock / categorías
        Route::get('/products', [ProductsController::class, 'index']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/categories', [CategoriesController::class, 'index']);

        // Imagen protegida (requiere token)
        Route::get('/products/{id}/image', [ProductsController::class, 'image']);

        // Ventas
        Route::post('/sales', [SalesController::class, 'store']);
        Route::get('/sales/{id}', [SalesController::class, 'show']);

        // ✅ evita 405 feo si alguien abre /api/sales en navegador
        Route::get('/sales', function () {
            return response()->json([
                'ok' => false,
                'message' => 'Use POST /api/sales to create a sale.'
            ], 405);
        });
    });

    /**
     * ✅ SOLO ADMIN: CRUD + stock
     */
    Route::middleware('role:admin')->group(function () {

        // Productos
        Route::post('/products', [ProductsController::class, 'store']);
        Route::get('/products/{id}', [ProductsController::class, 'show']);
        Route::put('/products/{id}', [ProductsController::class, 'update']);
        Route::delete('/products/{id}', [ProductsController::class, 'destroy']);
        Route::post('/products/{id}/stock', [ProductsController::class, 'addStock']);

        // Categorías
        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::put('/categories/{id}', [CategoriesController::class, 'update']);
        Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);
    });
});
