<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable','integer','min:1'],
            'customer_name' => ['nullable','string','max:160'],

            'payment_method_id' => ['required','integer','min:1'],

            'discount_type' => ['nullable','in:none,order_pct,order_amount'],
            'discount_value' => ['nullable','numeric','min:0'],

            'notes' => ['nullable','string'],

            'items' => ['required','array','min:1'],
            'items.*.product_id' => ['required','integer','min:1'],
            'items.*.variant_id' => ['nullable','integer','min:1'],
            'items.*.qty' => ['required','integer','min:1'],
            'items.*.unit_price' => ['required','numeric','min:0'],

            'items.*.item_discount_type' => ['nullable','in:none,pct,amount'],
            'items.*.item_discount_value' => ['nullable','numeric','min:0'],

            'items.*.axis' => ['nullable','integer','min:0','max:180'],
            'items.*.item_notes' => ['nullable','string'],
        ]);

        $user = $request->user();
        $userId = optional($user)->id;
        $userRoleId = (int) optional($user)->role_id;

        // defaults
        $discountType = $data['discount_type'] ?? 'none';
        $discountValue = (float)($data['discount_value'] ?? 0);

        // ✅ Resolver customer_id / customer_name:
        // - óptica (role_id=3): FORZAR a user.id / user.name
        // - admin/empleado: usar payload
        $customerId = $data['customer_id'] ?? null;
        $customerName = ($data['customer_name'] ?? 'Mostrador');

        if ($userRoleId === 3) {
            $customerId = $userId;                // ✅ users.id
            $customerName = $user?->name ?? 'Óptica';
        } else {
            // Si admin/empleado manda customer_id, validar que sea una "óptica" (role_id=3) en users
            if (!empty($customerId)) {
                $existsOpticaUser = DB::table('users')
                    ->whereNull('deleted_at')
                    ->where('active', 1)
                    ->where('role_id', 3)
                    ->where('id', (int)$customerId)
                    ->exists();

                if (!$existsOpticaUser) {
                    return response()->json([
                        'message' => 'Cliente inválido (óptica no encontrada en users)',
                        'errors' => ['customer_id' => ['Cliente inválido (óptica no encontrada en users)']]
                    ], 422);
                }

                // Si NO mandan customer_name, auto-llenar desde users
                if (empty($data['customer_name'])) {
                    $customerName = (string) (DB::table('users')->where('id', (int)$customerId)->value('name') ?? 'Óptica');
                }
            }
        }

        // Validar que payment_method_id exista
        $pmExists = DB::table('payment_methods')
            ->whereNull('deleted_at')
            ->where('id', (int)$data['payment_method_id'])
            ->exists();

        if (!$pmExists) {
            return response()->json([
                'message' => 'Método de pago inválido',
                'errors' => ['payment_method_id' => ['Método de pago inválido']]
            ], 422);
        }

        return DB::transaction(function () use ($data, $userId, $discountType, $discountValue, $customerId, $customerName) {

            // 1) Lock de inventario y validar stock suficiente
            foreach ($data['items'] as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['qty'];

                $row = DB::table('inventory')
                    ->where('product_id', $pid)
                    ->lockForUpdate()
                    ->first();

                $stock = (int)($row->stock ?? 0);

                if ($stock < $qty) {
                    abort(response()->json([
                        'message' => 'Stock insuficiente',
                        'errors' => [
                            'items' => ["Producto {$pid}: stock={$stock}, qty={$qty}"]
                        ],
                    ], 422));
                }
            }

            // 2) Calcular totales por ítem
            $lines = [];
            $subtotal = 0.0;
            $itemsDiscountTotal = 0.0;

            foreach ($data['items'] as $it) {
                $qty = (int)$it['qty'];
                $unit = (float)$it['unit_price'];
                $lineSubtotal = $unit * $qty;

                $itemDiscType = $it['item_discount_type'] ?? 'none';
                $itemDiscValue = (float)($it['item_discount_value'] ?? 0);

                $itemDiscAmount = 0.0;
                if ($itemDiscType === 'pct') {
                    $pct = max(0.0, min(100.0, $itemDiscValue));
                    $itemDiscAmount = $lineSubtotal * ($pct / 100.0);
                } elseif ($itemDiscType === 'amount') {
                    $itemDiscAmount = min($lineSubtotal, max(0.0, $itemDiscValue));
                }

                $lineTotal = max(0.0, $lineSubtotal - $itemDiscAmount);

                $subtotal += $lineSubtotal;
                $itemsDiscountTotal += $itemDiscAmount;

                $lines[] = [
                    'product_id' => (int)$it['product_id'],
                    'variant_id' => isset($it['variant_id']) ? (int)$it['variant_id'] : null,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'line_subtotal' => $lineSubtotal,

                    'item_discount_type' => $itemDiscType,
                    'item_discount_value' => (float)($it['item_discount_value'] ?? 0),
                    'item_discount_amount' => $itemDiscAmount,

                    'line_total' => $lineTotal,

                    'axis' => $it['axis'] ?? null,
                    'item_notes' => $it['item_notes'] ?? null,
                ];
            }

            // 3) Descuento a nivel pedido
            $orderDiscountAmount = 0.0;
            if ($discountType === 'order_pct') {
                $pct = max(0.0, min(100.0, $discountValue));
                $orderDiscountAmount = $subtotal * ($pct / 100.0);
            } elseif ($discountType === 'order_amount') {
                $orderDiscountAmount = min($subtotal, max(0.0, $discountValue));
            }

            $discountAmount = $itemsDiscountTotal + $orderDiscountAmount;
            $total = max(0.0, $subtotal - $discountAmount);

            // 4) Insert en sales
            $saleId = DB::table('sales')->insertGetId([
                'sold_by' => $userId,

                // ✅ Aquí ya aplica la lógica nueva
                'customer_id' => $customerId,
                'customer_name' => $customerName,

                'payment_method_id' => (int)$data['payment_method_id'],

                'discount_type' => $discountType,
                'discount_value' => (float)$discountValue,

                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total' => $total,

                'notes' => $data['notes'] ?? null,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 5) Insert sale_items + descontar stock + movimientos
            foreach ($lines as $ln) {
                DB::table('sale_items')->insert([
                    'sale_id' => $saleId,
                    'product_id' => $ln['product_id'],
                    'variant_id' => $ln['variant_id'],
                    'qty' => $ln['qty'],
                    'unit_price' => $ln['unit_price'],
                    'line_subtotal' => $ln['line_subtotal'],
                    'item_discount_type' => $ln['item_discount_type'],
                    'item_discount_value' => $ln['item_discount_value'],
                    'item_discount_amount' => $ln['item_discount_amount'],
                    'line_total' => $ln['line_total'],
                    'axis' => $ln['axis'],
                    'item_notes' => $ln['item_notes'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventory')
                    ->where('product_id', $ln['product_id'])
                    ->update([
                        'stock' => DB::raw('stock - '.(int)$ln['qty']),
                        'updated_at' => now(),
                    ]);

                if (DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                    DB::table('inventory_movements')->insert([
                        'product_id' => $ln['product_id'],
                        'variant_id' => $ln['variant_id'],
                        'movement_type' => 'out',
                        'qty' => $ln['qty'],
                        'reference_type' => 'sale',
                        'reference_id' => $saleId,
                        'note' => 'Venta POS',
                        'created_by' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json([
                'ok' => true,
                'sale_id' => $saleId,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total' => $total,
            ], 201);
        });
    }

    public function show($id)
    {
        $sale = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->first();

        if(!$sale){
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        $items = DB::table('sale_items as si')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->whereNull('si.deleted_at')
            ->where('si.sale_id', $sale->id)
            ->select([
                'si.id',
                'si.product_id',
                'p.sku as sku',
                'p.name as name',
                'si.variant_id',
                'si.qty',
                'si.unit_price',
                'si.line_subtotal',
                'si.item_discount_type',
                'si.item_discount_value',
                'si.item_discount_amount',
                'si.line_total',
                'si.axis',
                'si.item_notes',
            ])
            ->get();

        return response()->json([
            'id' => $sale->id,
            'sold_by' => $sale->sold_by,
            'customer_id' => $sale->customer_id,
            'customer_name' => $sale->customer_name,
            'payment_method_id' => $sale->payment_method_id,
            'discount_type' => $sale->discount_type,
            'discount_value' => (float)$sale->discount_value,
            'subtotal' => (float)$sale->subtotal,
            'discount_amount' => (float)$sale->discount_amount,
            'total' => (float)$sale->total,
            'notes' => $sale->notes,
            'created_at' => $sale->created_at,
            'items' => $items,
        ]);
    }
}
