<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ProductsController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Illuminate\Http\Request $request) {
        return $request->user();
    });
});

Route::middleware(['auth:sanctum'])->get('/me', function () {
    return response()->json([
        'ok' => true,
        'user' => request()->user(),
        'role' => request()->user()?->role?->name,
    ]);
});

Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin-test', function () {
    return response()->json([
        'ok' => true,
        'message' => 'Eres admin ✅'
    ]);
});

Route::middleware(['auth:sanctum', 'role:employee'])->get('/employee-test', function () {
    return response()->json([
        'ok' => true,
        'message' => 'Eres empleado ✅'
    ]);
});

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/inventory', [InventoryController::class, 'index']);

});

Route::get('/inventory', [InventoryController::class, 'index'])->middleware('auth:sanctum');
Route::get('/categories', [CategoriesController::class, 'index'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum','role:admin'])->group(function () {
     Route::get('/products', [ProductsController::class, 'index']);
    Route::post('/products', [ProductsController::class, 'store']);
    Route::put('/products/{id}', [ProductsController::class, 'update']);     // ✅ NECESARIA
    Route::delete('/products/{id}', [ProductsController::class, 'destroy']);
      Route::post('/products/{id}/stock', [ProductsController::class, 'addStock']);

    // (opcional) si usarás FormData con POST + _method=PUT
    Route::post('/products/{id}', [ProductsController::class, 'update']);   // ✅ OPCIONAL
    Route::get('/products/{id}/image', [ProductsController::class, 'image'])
    ->middleware(['auth:sanctum']);

    // Categories
    Route::get('/categories', [CategoriesController::class, 'index']);
    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::put('/categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    // (opcional)
    Route::post('/categories/{id}', [CategoriesController::class, 'update']);
});
// Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
//     Route::get('/admin-only', fn () => ['ok' => true]);
// });

// Route::middleware(['auth:sanctum', 'role:admin,employee'])->group(function () {
//     Route::get('/staff', fn () => ['ok' => true]);
// });

// Route::middleware(['auth:sanctum', 'role:optica'])->group(function () {
//     Route::get('/optica', fn () => ['ok' => true]);
// });

// Route::middleware('auth:sanctum')->group(function () {
//     Route::get('/auth/me', [AuthController::class, 'me']);
//     Route::post('/auth/logout', [AuthController::class, 'logout']);
// });
