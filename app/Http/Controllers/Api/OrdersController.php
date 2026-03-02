<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdersController extends Controller
{
    // ✅ Lista pedidos
    public function index(Request $request)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        // ✅ Solo estos roles pueden ver pedidos
        if (!in_array($role, ['optica', 'admin', 'employee'], true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // ✅ per_page controlado (evita que pidan 5000)
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min(100, $perPage)); // 1..100

        $q = Order::query()
            ->whereNull('deleted_at')
            ->with([
                // Ajusta columnas según tu schema real
                'items.product:id,sku,name,category_id,type,sale_price'
            ])
            ->orderByDesc('id');

        // ✅ Óptica: solo sus pedidos
        if ($role === 'optica') {
            if (!$u->optica_id) {
                // respuesta paginada vacía para mantener formato consistente
                return response()->json([
                    'current_page' => 1,
                    'data' => [],
                    'first_page_url' => null,
                    'from' => null,
                    'last_page' => 1,
                    'last_page_url' => null,
                    'links' => [],
                    'next_page_url' => null,
                    'path' => $request->url(),
                    'per_page' => $perPage,
                    'prev_page_url' => null,
                    'to' => null,
                    'total' => 0,
                ]);
            }
            $q->where('optica_id', $u->optica_id);
        }

        // ✅ Admin/Employee: ven todo (sin filtro)

        return response()->json(
            $q->paginate($perPage)->appends($request->query())
        );
    }

    // ✅ Crear pedido
    public function store(Request $request)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if ($role !== 'optica') {
            return response()->json(['message' => 'Solo óptica puede crear pedidos'], 403);
        }

        if (!$u->optica_id) {
            return response()->json(['message' => 'Usuario óptica sin optica_id'], 422);
        }

        $data = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],

            'items.*.axis' => ['nullable', 'integer'],
            'items.*.item_notes' => ['nullable', 'string'],
        ]);

        return \DB::transaction(function () use ($data, $u) {

            // 1) LOCK + validar AVAILABLE suficiente
            foreach ($data['items'] as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['qty'];

                $inv = \DB::table('inventory')
                    ->where('product_id', $pid)
                    ->lockForUpdate()
                    ->first();

                $stock = (int)($inv->stock ?? 0);
                $reserved = (int)($inv->reserved ?? 0);
                $available = $stock - $reserved;

                if ($available < $qty) {
                    abort(response()->json([
                        'message' => 'Stock disponible insuficiente',
                        'errors' => [
                            'items' => ["Producto {$pid}: stock={$stock}, reserved={$reserved}, available={$available}, qty={$qty}"]
                        ]
                    ], 422));
                }
            }

            // 2) Totales
            $subtotal = collect($data['items'])->sum(function ($it) {
                return (float)$it['qty'] * (float)$it['unit_price'];
            });

            // 3) Crear order
            $order = \App\Models\Order::create([
                'optica_id' => $u->optica_id,
                'created_by_user_id' => $u->id,
                'payment_method_id' => $data['payment_method_id'],
                'payment_status' => 'pendiente',
                'process_status' => 'en_proceso',
                'notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            // 4) Crear items + incrementar RESERVED
            foreach ($data['items'] as $it) {
                \App\Models\OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => (int)$it['product_id'],
                    'variant_id' => $it['variant_id'] ?? null,
                    'qty' => (int)$it['qty'],
                    'unit_price' => (float)$it['unit_price'],
                    'line_total' => (float)$it['qty'] * (float)$it['unit_price'],
                    'axis' => $it['axis'] ?? null,
                    'item_notes' => $it['item_notes'] ?? null,
                ]);

                // ✅ B1: reservar (NO tocar stock)
                \DB::table('inventory')
                    ->where('product_id', (int)$it['product_id'])
                    ->update([
                        'reserved' => \DB::raw('reserved + '.(int)$it['qty']),
                        'updated_at' => now(),
                    ]);

                // opcional: movement
                if (\DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                    \DB::table('inventory_movements')->insert([
                        'product_id' => (int)$it['product_id'],
                        'variant_id' => $it['variant_id'] ?? null,
                        'movement_type' => 'reserve',
                        'qty' => (int)$it['qty'],
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'note' => 'Reserva por pedido',
                        'created_by' => $u->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 5) Respuesta (incluye SKU/NAME para que tu modal no dependa del productsMap)
            $order->load(['items.product:id,sku,name,category_id,type,sale_price,sphere,cylinder']);

            return response()->json($order, 201);
        });
    }

    // ✅ Opcional: detalle
    public function show(Request $request, $id)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if ($role !== 'optica') return response()->json(['message' => 'No autorizado'], 403);

        $order = Order::with(['items.product:id,sku,name,category_id,type,sale_price'])->findOrFail($id);

        if ((int)$order->optica_id !== (int)$u->optica_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json($order);
    }
    public function cancel(Request $request, $id)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        // Ajusta permisos a tu regla real
        if (!in_array($role, ['optica','admin','employee'], true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return DB::transaction(function () use ($id, $u, $role) {

            // 1) Lock del pedido
            $order = Order::query()
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->with(['items']) // items: product_id, qty
                ->findOrFail($id);

            // 2) Regla: óptica solo puede cancelar los suyos
            if ($role === 'optica' && (int)$order->optica_id !== (int)$u->optica_id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }

            // 3) Validación de estado (ACLARA TU REGLA AQUÍ)
            // Yo recomiendo: SOLO si está en_preparacion
            if ($order->process_status !== 'en_preparacion') {
                return response()->json([
                    'message' => 'Solo se puede cancelar cuando está en preparación',
                    'errors' => ['process_status' => ['Estado no cancelable']]
                ], 422);
            }

            // 4) Devolver reserved
            foreach ($order->items as $it) {
                $pid = (int)$it->product_id;
                $qty = (int)$it->qty;

                $inv = DB::table('inventory')
                    ->where('product_id', $pid)
                    ->lockForUpdate()
                    ->first();

                if (!$inv) {
                    // Esto es un dato corrupto. Decide si abortas o ignoras.
                    abort(response()->json([
                        'message' => 'Inventario no encontrado',
                        'errors' => ['inventory' => ["No existe inventory para product_id={$pid}"]]
                    ], 422));
                }

                // Evita negativos: reserved = GREATEST(reserved - qty, 0)
                DB::table('inventory')
                    ->where('product_id', $pid)
                    ->update([
                        'reserved' => DB::raw('GREATEST(reserved - '.(int)$qty.', 0)'),
                        'updated_at' => now(),
                    ]);
            }

            // 5) Marcar cancelado
            $order->process_status = 'cancelado';
            $order->save();

            return response()->json(['ok' => true, 'order_id' => $order->id], 200);
        });
    }
}