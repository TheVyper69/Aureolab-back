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

        // ✅ Óptica: solo sus pedidos
        if ($role !== 'optica') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!$u->optica_id) {
            return response()->json([], 200);
        }

        $orders = Order::query()
            ->whereNull('deleted_at')
            ->where('optica_id', $u->optica_id) // orders.optica_id = users.optica_id (opticas.id)
            ->with(['items.product:id,sku,name,category_id,type,sale_price'])
            ->orderByDesc('id')
            ->get();

        return response()->json($orders);
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

        // ✅ estabas leyendo estos campos pero no los validabas
        'items.*.axis' => ['nullable', 'integer'],
        'items.*.item_notes' => ['nullable', 'string'],
    ]);

    return DB::transaction(function () use ($data, $u) {

        $subtotal = collect($data['items'])->sum(function ($it) {
            return (float)$it['qty'] * (float)$it['unit_price'];
        });

        $order = Order::create([
            'optica_id' => $u->optica_id,
            'created_by_user_id' => $u->id,
            'payment_method_id' => $data['payment_method_id'],
            'payment_status' => 'pendiente',
            'process_status' => 'en_proceso',
            'notes' => $data['notes'] ?? null,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);

        foreach ($data['items'] as $it) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $it['product_id'],
                'variant_id' => $it['variant_id'] ?? null,
                'qty' => (int)$it['qty'],
                'unit_price' => (float)$it['unit_price'],
                'line_total' => (float)$it['qty'] * (float)$it['unit_price'],
                'axis' => $it['axis'] ?? null,
                'item_notes' => $it['item_notes'] ?? null,
            ]);
        }

        // ✅ FIX: usa nombres reales de columnas en products
        // Opción segura: carga el producto completo
        // $order->load(['items.product']);

        // Opción “select” (si quieres limitar columnas):
        $order->load(['items.product:id,sku,name,category_id,type,sale_price']);

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
}