<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;

class SalesController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => ['required','array','min:1'],
            'items.*.id' => ['required','integer'],
            'items.*.qty' => ['required','integer','min:1'],

            'method' => ['required','string','max:30'],         // cash/card/transfer
            'customerName' => ['nullable','string','max:140'],

            'subtotal' => ['required','numeric','min:0'],
            'discountMode' => ['nullable','string','max:20'],   // none/order/item
            'orderDiscountPct' => ['nullable','numeric','min:0','max:100'],
            'discountAmount' => ['nullable','numeric','min:0'],
            'total' => ['required','numeric','min:0'],
        ]);

        $userId = optional($request->user())->id;

        return DB::transaction(function () use ($data, $userId) {

            // 1) Validar stock antes de registrar
            foreach ($data['items'] as $it) {
                $pid = (int)$it['id'];
                $qty = (int)$it['qty'];

                $row = DB::table('inventory')->where('product_id', $pid)->lockForUpdate()->first();
                $stock = (int)($row->stock ?? 0);

                if ($stock < $qty) {
                    abort(response()->json([
                        'message' => 'Stock insuficiente',
                        'errors' => [
                            'items' => ["El producto {$pid} no tiene stock suficiente (stock {$stock}, pedido {$qty})."]
                        ]
                    ], 422));
                }
            }

            // 2) Crear venta
            $saleId = DB::table('sales')->insertGetId([
                'customer_name' => $data['customerName'] ?? 'Mostrador',
                'payment_method' => $data['method'],
                'subtotal' => (float)$data['subtotal'],
                'discount_mode' => $data['discountMode'] ?? 'none',
                'order_discount_pct' => (float)($data['orderDiscountPct'] ?? 0),
                'discount_amount' => (float)($data['discountAmount'] ?? 0),
                'total' => (float)$data['total'],
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3) Insertar items + descontar stock
            foreach ($data['items'] as $it) {
                $pid = (int)$it['id'];
                $qty = (int)$it['qty'];

                $p = Product::select('id','sku','name','sale_price','buy_price')->find($pid);
                if(!$p){
                    abort(response()->json([
                        'message' => 'Producto inválido',
                        'errors' => ['items' => ["Producto {$pid} no existe."]]
                    ], 422));
                }

                // precio capturado al momento de venta
                $unitPrice = (float)($it['salePrice'] ?? $it['sale_price'] ?? $p->sale_price ?? 0);

                DB::table('sale_items')->insert([
                    'sale_id' => $saleId,
                    'product_id' => $pid,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice * $qty,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // movimiento (si tienes tabla)
                if (DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                    DB::table('inventory_movements')->insert([
                        'product_id' => $pid,
                        'variant_id' => null,
                        'movement_type' => 'out',
                        'qty' => $qty,
                        'reference_type' => 'sale',
                        'reference_id' => $saleId,
                        'note' => 'Venta POS',
                        'created_by' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // descontar stock
                DB::table('inventory')
                    ->where('product_id', $pid)
                    ->update([
                        'stock' => DB::raw('stock - '.$qty),
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'ok' => true,
                'sale_id' => $saleId
            ], 201);
        });
    }

    public function show($id)
    {
        $sale = DB::table('sales')->where('id', $id)->first();
        if(!$sale){
            return response()->json(['message' => 'Venta no encontrada'], 404);
        }

        $items = DB::table('sale_items')
            ->where('sale_id', $id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'sale' => $sale,
            'items' => $items,
        ]);
    }
}
