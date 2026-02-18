<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Models\Role;


class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email', $request->email)
            ->whereNull('deleted_at')
            ->first();

        if (!$user || !$user->active || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        // Sanctum token
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada',
        ]);
    }

    public function register(Request $request)
{
    $data = $request->validate([
        'name' => ['required','string','max:120'],
        'email' => ['required','email','max:190','unique:users,email'],
        'phone' => ['nullable','string','max:40'],

        // roles permitidos
        'role' => ['required','string','in:admin,employee,optica'],

        // solo aplica si role = optica (y ya existe en tabla opticas)
        'optica_id' => ['nullable','integer'],

        'password' => ['required','string','min:6','confirmed'],
        'active' => ['nullable','boolean'],
    ]);

    // role_id por nombre
    $roleId = \Illuminate\Support\Facades\DB::table('roles')
        ->where('name', $data['role'])
        ->value('id');

    if(!$roleId){
        return response()->json(['message' => 'Rol no encontrado en tabla roles.'], 422);
    }

    // ✅ reglas de negocio:
    // - empleado: NO debe traer optica_id
    if($data['role'] === 'employee'){
        $data['optica_id'] = null;
    }

    // - optica: si mandas optica_id, validar que exista
    if($data['role'] === 'optica' && !empty($data['optica_id'])){
        $exists = \Illuminate\Support\Facades\DB::table('opticas')
            ->where('id', (int)$data['optica_id'])
            ->exists();

        if(!$exists){
            return response()->json([
                'message' => 'optica_id no existe en tabla opticas.',
                'errors' => ['optica_id' => ['El optica_id no existe.']]
            ], 422);
        }
    }

    $user = User::create([
        'role_id' => $roleId,
        'optica_id' => $data['optica_id'] ?? null,
        'name' => $data['name'],
        'email' => $data['email'],
        'phone' => $data['phone'] ?? null,
        'password' => Hash::make($data['password']),
        'active' => array_key_exists('active', $data) ? (int)$data['active'] : 1,
    ]);

    return response()->json([
        'message' => 'Usuario creado',
        'user' => $user,
    ], 201);
}

}