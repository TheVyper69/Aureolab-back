<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OpticasController extends Controller
{
    /**
     * Lista ópticas (para autocomplete POS)
     * Regresa customer_id = opticas.customer_id (FK a customers.id)
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // Traemos desde users (role optica) pero unimos a opticas para obtener customer_id espejo
        $query = DB::table('users as u')
            ->join('opticas as o', 'o.id', '=', 'u.optica_id')
            ->whereNull('u.deleted_at')
            ->where('u.active', 1)
            ->where('u.role_id', 3)
            ->whereNull('o.deleted_at')
            ->where('o.active', 1);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                    ->orWhere('u.email', 'like', "%{$q}%")
                    ->orWhere('u.phone', 'like', "%{$q}%")
                    ->orWhere('o.nombre', 'like', "%{$q}%")
                    ->orWhere('o.email', 'like', "%{$q}%")
                    ->orWhere('o.telefono', 'like', "%{$q}%");
            });
        }

        $rows = $query
            ->select([
                // ✅ ESTE es el customer_id que debe ir a sales.customer_id
                'o.customer_id as customer_id',

                // nombre para mostrar
                DB::raw("COALESCE(NULLIF(u.name,''), o.nombre) as customer_name"),

                'u.email as email',
                'u.phone as phone',

                'u.id as user_id',
                'o.id as optica_id',
            ])
            ->orderBy('customer_name')
            ->limit(50)
            ->get();

        return response()->json($rows);
    }

    /**
     * Crea:
     * - customers (espejo)
     * - optica (tabla opticas) con customer_id
     * - user optica (tabla users con role optica y optica_id)
     * - métodos de pago permitidos (optica_payment_methods)
     *
     * Requiere: auth:sanctum + role:admin (según tu middleware)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // optica
            'optica.nombre'   => ['required','string','max:160'],
            'optica.contacto' => ['nullable','string','max:160'],
            'optica.telefono' => ['nullable','string','max:40'],
            'optica.email'    => ['nullable','email','max:190'],
            'optica.active'   => ['nullable','boolean'],

            // payment methods ids (opcional)
            'payment_method_ids'   => ['nullable','array'],
            'payment_method_ids.*' => ['integer'],

            // user optica (obligatorio)
            'user.name'     => ['required','string','max:120'],
            'user.email'    => ['required','email','max:190','unique:users,email'],
            'user.phone'    => ['nullable','string','max:40'],
            'user.password' => ['required','string','min:6','confirmed'],
            'user.active'   => ['nullable','boolean'],
        ]);

        $opticaRoleId = DB::table('roles')->where('name', 'optica')->value('id');
        if (!$opticaRoleId) {
            return response()->json([
                'message' => 'No existe el rol "optica" en tabla roles.'
            ], 422);
        }

        return DB::transaction(function () use ($data, $opticaRoleId) {

            $opt = $data['optica'];
            $u   = $data['user'];

            // =========================================================
            // 0) Crear CUSTOMER ESPEJO (customers)
            // =========================================================
            $customerName = trim((string)($u['name'] ?? '')) ?: trim((string)($opt['nombre'] ?? 'Óptica'));
            $customerId = DB::table('customers')->insertGetId([
                'name'       => $customerName,
                'phone'      => $u['phone'] ?? ($opt['telefono'] ?? null),
                'email'      => $u['email'] ?? ($opt['email'] ?? null),
                'notes'      => 'Cliente espejo (óptica)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // =========================================================
            // 1) Crear OPTICA y guardar customer_id espejo
            // =========================================================
            $opticaId = DB::table('opticas')->insertGetId([
                'nombre'     => $opt['nombre'],
                'contacto'   => $opt['contacto'] ?? null,
                'telefono'   => $opt['telefono'] ?? null,
                'email'      => $opt['email'] ?? null,
                'active'     => array_key_exists('active', $opt) ? (int)$opt['active'] : 1,

                // ✅ clave
                'customer_id' => $customerId,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // =========================================================
            // 2) Asignar métodos de pago a la óptica (opcional)
            // =========================================================
            $pmIds = $data['payment_method_ids'] ?? [];
            if (!empty($pmIds)) {
                $existing = DB::table('payment_methods')
                    ->whereIn('id', $pmIds)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->map(fn ($x) => (int)$x)
                    ->all();

                $missing = array_values(array_diff(array_map('intval', $pmIds), $existing));
                if (!empty($missing)) {
                    // rollback automático por excepción? aquí retornamos response, pero en transaction
                    // Laravel hace commit SOLO si no hay exception; devolviendo response no lanza exception,
                    // así que mejor abort para forzar rollback.
                    abort(response()->json([
                        'message' => 'Uno o más payment_method_id no existen.',
                        'errors'  => ['payment_method_ids' => ['No existen: ' . implode(',', $missing)]]
                    ], 422));
                }

                $rows = [];
                foreach (array_unique($pmIds) as $pmId) {
                    $rows[] = [
                        'optica_id'         => $opticaId,
                        'payment_method_id' => (int)$pmId,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];
                }

                DB::table('optica_payment_methods')->insert($rows);
            }

            // =========================================================
            // 3) Crear USER OPTICA (users.optica_id = opticas.id)
            // =========================================================
            $user = User::create([
                'role_id'   => $opticaRoleId,
                'optica_id' => $opticaId,
                'name'      => $u['name'],
                'email'     => $u['email'],
                'phone'     => $u['phone'] ?? null,
                'password'  => Hash::make($u['password']),
                'active'    => array_key_exists('active', $u) ? (int)$u['active'] : 1,
            ]);

            // =========================================================
            // 4) Respuesta
            // =========================================================
            return response()->json([
                'message' => 'Óptica, customer espejo y usuario creados',
                'customer' => [
                    'id'   => $customerId,
                    'name' => $customerName,
                ],
                'optica' => [
                    'id'          => $opticaId,
                    'customer_id' => $customerId,
                    'nombre'      => $opt['nombre'],
                    'contacto'    => $opt['contacto'] ?? null,
                    'telefono'    => $opt['telefono'] ?? null,
                    'email'       => $opt['email'] ?? null,
                    'active'      => array_key_exists('active', $opt) ? (int)$opt['active'] : 1,
                ],
                'user' => $user,
                'payment_method_ids' => array_values(array_unique(array_map('intval', $pmIds))),
            ], 201);
        });
    }
}
