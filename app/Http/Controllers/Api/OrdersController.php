<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdersController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if (!in_array($role, ['optica', 'admin', 'employee'], true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min(100, $perPage));

        $q = Order::query()
            ->whereNull('deleted_at')
            ->with([
                'items.product:id,sku,name,category_id,type,sale_price,sphere,cylinder',
            ])
            ->orderByDesc('id');

        if ($role === 'optica') {
            if (!$u->optica_id) {
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

        $orders = $q->paginate($perPage)->appends($request->query());

        $orderIds = collect($orders->items())->pluck('id')->all();

        if (!empty($orderIds)) {
            $itemsWithTreatments = DB::table('order_items as oi')
                ->leftJoin('order_item_treatments as oit', 'oit.order_item_id', '=', 'oi.id')
                ->leftJoin('treatments as t', 't.id', '=', 'oit.treatment_id')
                ->whereIn('oi.order_id', $orderIds)
                ->select(
                    'oi.id as order_item_id',
                    't.id as treatment_id',
                    't.name as treatment_name',
                    't.code as treatment_code'
                )
                ->get()
                ->groupBy('order_item_id');

            $orders->getCollection()->transform(function ($order) use ($itemsWithTreatments) {
                $order->items->transform(function ($item) use ($itemsWithTreatments) {
                    $item->treatments = collect($itemsWithTreatments->get($item->id, []))
                        ->filter(fn ($row) => !empty($row->treatment_id))
                        ->map(fn ($row) => [
                            'id' => $row->treatment_id,
                            'name' => $row->treatment_name,
                            'code' => $row->treatment_code,
                        ])
                        ->values();

                    return $item;
                });

                return $order;
            });
        }

        return response()->json($orders);
    }

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
            'items.*.treatments' => ['nullable', 'array'],
            'items.*.treatments.*' => ['integer', 'exists:treatments,id'],
        ]);

        return DB::transaction(function () use ($data, $u) {
            $preparedItems = [];
            $subtotal = 0;

            foreach ($data['items'] as $idx => $it) {
                $pid = (int) $it['product_id'];
                $qty = (int) $it['qty'];

                $inv = DB::table('inventory')
                    ->where('product_id', $pid)
                    ->lockForUpdate()
                    ->first();

                $stock = (int) ($inv->stock ?? 0);
                $reserved = (int) ($inv->reserved ?? 0);
                $available = $stock - $reserved;

                if ($available < $qty) {
                    abort(response()->json([
                        'message' => 'Stock disponible insuficiente',
                        'errors' => [
                            'items' => [
                                "Producto {$pid}: stock={$stock}, reserved={$reserved}, available={$available}, qty={$qty}"
                            ]
                        ]
                    ], 422));
                }

                $product = DB::table('products as p')
                    ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                    ->where('p.id', $pid)
                    ->select(
                        'p.id',
                        'p.category_id',
                        'p.sale_price',
                        'c.code as category_code',
                        'c.name as category_name'
                    )
                    ->first();

                if (!$product) {
                    abort(response()->json([
                        'message' => 'Producto no encontrado',
                        'errors' => [
                            'items' => ["No existe el producto {$pid}"]
                        ]
                    ], 422));
                }

                $isMica = strtoupper((string) ($product->category_code ?? '')) === 'MICAS';

                $treatments = collect($it['treatments'] ?? [])
                    ->map(fn ($v) => (int) $v)
                    ->filter(fn ($v) => $v > 0)
                    ->unique()
                    ->values();

                if (!$isMica && $treatments->isNotEmpty()) {
                    abort(response()->json([
                        'message' => 'Solo las micas pueden llevar tratamientos',
                        'errors' => [
                            "items.{$idx}.treatments" => [
                                "El producto {$pid} no pertenece a la categoría MICAS."
                            ]
                        ]
                    ], 422));
                }

                if ($isMica && $treatments->isNotEmpty()) {
                    $allowed = DB::table('product_treatments')
                        ->where('product_id', $pid)
                        ->pluck('treatment_id')
                        ->map(fn ($v) => (int) $v)
                        ->all();

                    if (!empty($allowed)) {
                        $invalid = $treatments
                            ->reject(fn ($id) => in_array($id, $allowed, true))
                            ->values()
                            ->all();

                        if (!empty($invalid)) {
                            abort(response()->json([
                                'message' => 'Tratamientos no permitidos para esta mica',
                                'errors' => [
                                    "items.{$idx}.treatments" => [
                                        'Tratamientos inválidos: ' . implode(', ', $invalid)
                                    ]
                                ]
                            ], 422));
                        }
                    }
                }

                $lineTotal = (float) $qty * (float) $it['unit_price'];
                $subtotal += $lineTotal;

                $preparedItems[] = [
                    'product_id' => $pid,
                    'variant_id' => $it['variant_id'] ?? null,
                    'qty' => $qty,
                    'unit_price' => (float) $it['unit_price'],
                    'line_total' => $lineTotal,
                    'axis' => $it['axis'] ?? null,
                    'item_notes' => $it['item_notes'] ?? null,
                    'treatments' => $treatments->all(),
                ];
            }

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

            foreach ($preparedItems as $it) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $it['product_id'],
                    'variant_id' => $it['variant_id'],
                    'qty' => $it['qty'],
                    'unit_price' => $it['unit_price'],
                    'line_total' => $it['line_total'],
                    'axis' => $it['axis'],
                    'item_notes' => $it['item_notes'],
                ]);

                foreach ($it['treatments'] as $treatmentId) {
                    DB::table('order_item_treatments')->insert([
                        'order_item_id' => $orderItem->id,
                        'treatment_id' => $treatmentId,
                        'price' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('inventory')
                    ->where('product_id', $it['product_id'])
                    ->update([
                        'reserved' => DB::raw('reserved + ' . (int) $it['qty']),
                        'updated_at' => now(),
                    ]);

                if (DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                    DB::table('inventory_movements')->insert([
                        'product_id' => $it['product_id'],
                        'variant_id' => $it['variant_id'],
                        'movement_type' => 'reserve',
                        'qty' => $it['qty'],
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'note' => 'Reserva por pedido',
                        'created_by' => $u->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $order->load([
                'items.product:id,sku,name,category_id,type,sale_price,sphere,cylinder'
            ]);

            $itemsWithTreatments = DB::table('order_items as oi')
                ->leftJoin('order_item_treatments as oit', 'oit.order_item_id', '=', 'oi.id')
                ->leftJoin('treatments as t', 't.id', '=', 'oit.treatment_id')
                ->where('oi.order_id', $order->id)
                ->select(
                    'oi.id as order_item_id',
                    't.id as treatment_id',
                    't.name as treatment_name',
                    't.code as treatment_code'
                )
                ->get()
                ->groupBy('order_item_id');

            $order->items->transform(function ($item) use ($itemsWithTreatments) {
                $item->treatments = collect($itemsWithTreatments->get($item->id, []))
                    ->filter(fn ($row) => !empty($row->treatment_id))
                    ->map(fn ($row) => [
                        'id' => $row->treatment_id,
                        'name' => $row->treatment_name,
                        'code' => $row->treatment_code,
                    ])
                    ->values();

                return $item;
            });

            return response()->json($order, 201);
        });
    }

    public function show(Request $request, $id)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if (!in_array($role, ['optica', 'admin', 'employee'], true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $order = Order::with([
            'items.product:id,sku,name,category_id,type,sale_price,sphere,cylinder'
        ])->findOrFail($id);

        if ($role === 'optica' && (int) $order->optica_id !== (int) $u->optica_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $itemsWithTreatments = DB::table('order_items as oi')
            ->leftJoin('order_item_treatments as oit', 'oit.order_item_id', '=', 'oi.id')
            ->leftJoin('treatments as t', 't.id', '=', 'oit.treatment_id')
            ->where('oi.order_id', $order->id)
            ->select(
                'oi.id as order_item_id',
                't.id as treatment_id',
                't.name as treatment_name',
                't.code as treatment_code'
            )
            ->get()
            ->groupBy('order_item_id');

        $order->items->transform(function ($item) use ($itemsWithTreatments) {
            $item->treatments = collect($itemsWithTreatments->get($item->id, []))
                ->filter(fn ($row) => !empty($row->treatment_id))
                ->map(fn ($row) => [
                    'id' => $row->treatment_id,
                    'name' => $row->treatment_name,
                    'code' => $row->treatment_code,
                ])
                ->values();

            return $item;
        });

        return response()->json($order);
    }

    public function cancel(Request $request, $id)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if (!in_array($role, ['optica', 'admin', 'employee'], true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return DB::transaction(function () use ($id, $u, $role) {
            $order = Order::query()
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->with(['items'])
                ->findOrFail($id);

            if ($role === 'optica' && (int) $order->optica_id !== (int) $u->optica_id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }

            if ($order->process_status !== 'en_preparacion') {
                return response()->json([
                    'message' => 'Solo se puede cancelar cuando está en preparación',
                    'errors' => ['process_status' => ['Estado no cancelable']]
                ], 422);
            }

            foreach ($order->items as $it) {
                $pid = (int) $it->product_id;
                $qty = (int) $it->qty;

                $inv = DB::table('inventory')
                    ->where('product_id', $pid)
                    ->lockForUpdate()
                    ->first();

                if (!$inv) {
                    abort(response()->json([
                        'message' => 'Inventario no encontrado',
                        'errors' => ['inventory' => ["No existe inventory para product_id={$pid}"]]
                    ], 422));
                }

                DB::table('inventory')
                    ->where('product_id', $pid)
                    ->update([
                        'reserved' => DB::raw('GREATEST(reserved - ' . (int) $qty . ', 0)'),
                        'updated_at' => now(),
                    ]);
            }

            $order->process_status = 'cancelado';
            $order->save();

            return response()->json(['ok' => true, 'order_id' => $order->id], 200);
        });
    }
}