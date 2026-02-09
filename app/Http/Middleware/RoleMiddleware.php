<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        // Si no hay usuario autenticado
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Cargar rol si no viene cargado
        if (! $user->relationLoaded('role')) {
            $user->load('role');
        }

        $roleName = $user->role?->name; // <- evita "Attempt to read property on null"

        if (!$roleName || !in_array($roleName, $roles, true)) {
            return response()->json([
                'message' => 'Acceso no autorizado',
                'role' => $roleName,
                'allowed' => $roles
            ], 403);
        }

        return $next($request);
    }
}