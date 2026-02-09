<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;

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