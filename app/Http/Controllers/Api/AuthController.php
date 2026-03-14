<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with('role:id,name')
            ->where('email', $data['email'])
            ->whereNull('deleted_at')
            ->first();

        if (!$user || !(int) $user->active || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'active' => (bool) $user->active,
                'role_id' => (int) $user->role_id,
                'role' => $user->role?->name,
                'optica_id' => $user->optica_id ? (int) $user->optica_id : null,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Sesión cerrada',
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', Rule::in(['admin', 'employee', 'optica'])],
            'optica_contacto' => ['nullable', 'string', 'max:190'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'active' => ['nullable', 'boolean'],
        ]);

        $role = DB::table('roles')
            ->whereNull('deleted_at')
            ->where('name', $data['role'])
            ->select('id', 'name')
            ->first();

        if (!$role) {
            return response()->json([
                'message' => 'Rol no encontrado en tabla roles.',
                'errors' => [
                    'role' => ['El rol no existe en tabla roles.']
                ]
            ], 422);
        }

        return DB::transaction(function () use ($data, $role) {
            $opticaId = null;
            $customerId = null;
            $active = array_key_exists('active', $data) ? (int) $data['active'] : 1;

            if ($role->name === 'optica') {
                if (empty($data['optica_contacto'])) {
                    return response()->json([
                        'message' => 'Debes capturar el contacto de la óptica.',
                        'errors' => [
                            'optica_contacto' => ['El contacto de la óptica es obligatorio.']
                        ]
                    ], 422);
                }

                $customerId = DB::table('customers')->insertGetId([
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'],
                    'notes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);

                $opticaId = DB::table('opticas')->insertGetId([
                    'nombre' => $data['name'],
                    'contacto' => $data['optica_contacto'],
                    'telefono' => $data['phone'] ?? null,
                    'email' => $data['email'],
                    'active' => $active,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                    'customer_id' => $customerId,
                ]);
            }

            $user = User::create([
                'role_id' => (int) $role->id,
                'optica_id' => $opticaId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'active' => $active,
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Usuario creado',
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'active' => (bool) $user->active,
                    'role_id' => (int) $user->role_id,
                    'role' => $role->name,
                    'optica_id' => $user->optica_id ? (int) $user->optica_id : null,
                ],
                'customer_id' => $customerId,
                'optica_id' => $opticaId,
            ], 201);
        });
    }

    public function usersIndex(Request $request)
    {
        $currentUserId = optional($request->user())->id;

        $rows = DB::table('users as u')
            ->join('roles as r', 'r.id', '=', 'u.role_id')
            ->leftJoin('opticas as o', 'o.id', '=', 'u.optica_id')
            ->whereNull('u.deleted_at')
            ->whereIn('r.name', ['employee', 'optica', 'admin'])
            ->when($currentUserId, function ($q) use ($currentUserId) {
                $q->where('u.id', '<>', $currentUserId);
            })
            ->select([
                'u.id',
                'u.name',
                'u.email',
                'u.phone',
                'u.active',
                'u.role_id',
                'u.optica_id',
                'r.name as role',
                'o.nombre as optica_nombre',
                'o.contacto as optica_contacto',
            ])
            ->orderBy('r.name')
            ->orderBy('u.name')
            ->get();

        return response()->json($rows->map(function ($u) {
            return [
                'id' => (int) $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'active' => (bool) $u->active,
                'role_id' => (int) $u->role_id,
                'role' => $u->role,
                'optica_id' => $u->optica_id ? (int) $u->optica_id : null,
                'optica_nombre' => $u->optica_nombre,
                'optica_contacto' => $u->optica_contacto,
            ];
        }));
    }


    public function updateUser(Request $request, $id)
    {
        $user = User::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', Rule::in(['admin', 'employee', 'optica'])],
            'optica_contacto' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'active' => ['nullable', 'boolean'],
        ]);

        $currentRoleName = DB::table('roles')
            ->where('id', $user->role_id)
            ->value('name');

        $newRole = DB::table('roles')
            ->whereNull('deleted_at')
            ->where('name', $data['role'])
            ->select('id', 'name')
            ->first();

        if (!$newRole) {
            return response()->json([
                'message' => 'Rol no encontrado en tabla roles.',
                'errors' => [
                    'role' => ['El rol no existe en tabla roles.']
                ]
            ], 422);
        }

        if ($currentRoleName === 'optica' && $newRole->name !== 'optica') {
            return response()->json([
                'message' => 'Una óptica no puede cambiar de rol.',
                'errors' => [
                    'role' => ['Las ópticas solo pueden permanecer como óptica.']
                ]
            ], 422);
        }

        if (in_array($currentRoleName, ['employee', 'admin'], true) && $newRole->name === 'optica') {
            return response()->json([
                'message' => 'Este usuario no puede convertirse en óptica desde edición.',
                'errors' => [
                    'role' => ['Solo empleado y admin pueden alternar entre sí.']
                ]
            ], 422);
        }

        if (in_array($currentRoleName, ['employee', 'admin'], true) && !in_array($newRole->name, ['employee', 'admin'], true)) {
            return response()->json([
                'message' => 'Cambio de rol inválido.',
                'errors' => [
                    'role' => ['Solo empleado y admin pueden alternar entre sí.']
                ]
            ], 422);
        }

        return DB::transaction(function () use ($data, $user, $newRole, $currentRoleName) {
            $active = array_key_exists('active', $data) ? (int) $data['active'] : (int) $user->active;

            if ($currentRoleName === 'optica') {
                if (empty($user->optica_id)) {
                    return response()->json([
                        'message' => 'El usuario óptica no tiene optica_id asociado.',
                        'errors' => [
                            'optica_id' => ['No existe relación con tabla opticas.']
                        ]
                    ], 422);
                }

                if (empty($data['optica_contacto'])) {
                    return response()->json([
                        'message' => 'Debes capturar el contacto de la óptica.',
                        'errors' => [
                            'optica_contacto' => ['El contacto de la óptica es obligatorio.']
                        ]
                    ], 422);
                }

                $optica = DB::table('opticas')
                    ->where('id', $user->optica_id)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$optica) {
                    return response()->json([
                        'message' => 'No se encontró la óptica asociada.',
                        'errors' => [
                            'optica_id' => ['La óptica asociada no existe o fue eliminada.']
                        ]
                    ], 422);
                }

                DB::table('opticas')
                    ->where('id', $optica->id)
                    ->update([
                        'nombre' => $data['name'],
                        'contacto' => $data['optica_contacto'],
                        'telefono' => $data['phone'] ?? null,
                        'email' => $data['email'],
                        'active' => $active,
                        'updated_at' => now(),
                    ]);

                if (!empty($optica->customer_id)) {
                    DB::table('customers')
                        ->where('id', $optica->customer_id)
                        ->whereNull('deleted_at')
                        ->update([
                            'name' => $data['name'],
                            'phone' => $data['phone'] ?? null,
                            'email' => $data['email'],
                            'updated_at' => now(),
                        ]);
                }
            }

            $user->role_id = (int) $newRole->id;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->phone = $data['phone'] ?? null;
            $user->active = $active;

            if (!empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            return response()->json([
                'ok' => true,
                'message' => 'Usuario actualizado',
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'active' => (bool) $user->active,
                    'role_id' => (int) $user->role_id,
                    'role' => $newRole->name,
                    'optica_id' => $user->optica_id ? (int) $user->optica_id : null,
                ],
            ]);
        });
    }

    public function deleteUser($id)
    {
        $user = User::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $roleName = DB::table('roles')
            ->where('id', $user->role_id)
            ->value('name');

        return DB::transaction(function () use ($user, $roleName) {
            if ($roleName === 'optica' && !empty($user->optica_id)) {
                $optica = DB::table('opticas')
                    ->where('id', $user->optica_id)
                    ->whereNull('deleted_at')
                    ->first();

                if ($optica) {
                    DB::table('opticas')
                        ->where('id', $optica->id)
                        ->update([
                            'deleted_at' => now(),
                            'updated_at' => now(),
                            'active' => 0,
                        ]);

                    if (!empty($optica->customer_id)) {
                        DB::table('customers')
                            ->where('id', $optica->customer_id)
                            ->whereNull('deleted_at')
                            ->update([
                                'deleted_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            $user->delete();

            return response()->json([
                'ok' => true,
                'message' => 'Usuario eliminado',
            ]);
        });
    }
}
