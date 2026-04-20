<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrdersController extends Controller
{
    private function resolveBiselCategoryId(): int
    {
        $cat = DB::table('categories')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereRaw('UPPER(TRIM(code)) = ?', ['BISEL'])
                  ->orWhereRaw('UPPER(TRIM(name)) = ?', ['BISEL'])
                  ->orWhereRaw('UPPER(TRIM(name)) LIKE ?', ['%BISEL%']);
            })
            ->select('id')
            ->first();

        if (!$cat) {
            abort(response()->json([
                'message' => 'No existe la categoría BISEL.',
                'errors' => [
                    'category' => ['Debes crear la categoría BISEL antes de usar biselado personalizado.']
                ]
            ], 422));
        }

        return (int) $cat->id;
    }

    private function treatmentsForOrderItems(array $orderItemIds)
    {
        if (empty($orderItemIds) || !DB::getSchemaBuilder()->hasTable('order_item_treatments')) {
            return collect();
        }

        return DB::table('order_item_treatments as oit')
            ->join('treatments as t', 't.id', '=', 'oit.treatment_id')
            ->whereIn('oit.order_item_id', $orderItemIds)
            ->whereNull('t.deleted_at')
            ->select(
                'oit.order_item_id',
                't.id as treatment_id',
                't.name as treatment_name',
                't.code as treatment_code',
                't.description as treatment_description',
                'oit.price as extra_price'
            )
            ->get()
            ->groupBy('order_item_id');
    }

    private function customBiselMap(array $orderItemIds)
    {
        if (empty($orderItemIds) || !DB::getSchemaBuilder()->hasTable('order_item_custom_bisel')) {
            return collect();
        }

        return DB::table('order_item_custom_bisel as cb')
            ->leftJoin('lens_types as lt', 'lt.id', '=', 'cb.lens_type_id')
            ->whereIn('cb.order_item_id', $orderItemIds)
            ->select([
                'cb.order_item_id',
                'cb.reflection',
                'cb.lens_type_id',
                'cb.frame_height',
                'cb.blank_height',
                'cb.observations',
                'lt.code as lens_type_code',
                'lt.name as lens_type_name',
            ])
            ->get()
            ->keyBy('order_item_id');
    }

    private function productDetailsMap(array $productIds)
    {
        if (empty($productIds)) {
            return collect();
        }

        $selects = [
            'p.id',
            'p.sku',
            'p.name',
            'p.description',
            'p.category_id',
            'p.type',
            'p.brand',
            'p.model',
            'p.material',
            'p.size',
            'p.buy_price',
            'p.sale_price',
            'p.min_stock',
            'p.max_stock',
            'p.supplier_id',
            'p.box_id',
            'p.lens_type_id',
            'p.material_id',
            'p.sphere',
            'p.cylinder',
            'p.axis',
            'p.image_path',
            'c.code as category_code',
            'c.name as category_name',
            's.name as supplier_name',
            'b.code as box_code',
            'b.name as box_name',
            'lt.code as lens_type_code',
            'lt.name as lens_type_name',
            'm.name as material_name',
        ];

        if (DB::getSchemaBuilder()->hasColumn('products', 'is_custom')) {
            $selects[] = 'p.is_custom';
        }

        if (DB::getSchemaBuilder()->hasColumn('products', 'show_in_pos')) {
            $selects[] = 'p.show_in_pos';
        }

        return DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('boxes as b', 'b.id', '=', 'p.box_id')
            ->leftJoin('lens_types as lt', 'lt.id', '=', 'p.lens_type_id')
            ->leftJoin('materials as m', 'm.id', '=', 'p.material_id')
            ->whereIn('p.id', $productIds)
            ->whereNull('p.deleted_at')
            ->select($selects)
            ->get()
            ->keyBy('id');
    }

    private function attachProductDetailsToOrders($orders): void
    {
        $orderCollection = $orders instanceof \Illuminate\Pagination\LengthAwarePaginator
            ? $orders->getCollection()
            : collect([$orders]);

        $productIds = $orderCollection
            ->flatMap(function ($order) {
                return collect($order->items ?? [])->pluck('product_id');
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $orderItemIds = $orderCollection
            ->flatMap(function ($order) {
                return collect($order->items ?? [])->pluck('id');
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $productDetailsById = $this->productDetailsMap($productIds);
        $treatmentsByOrderItem = $this->treatmentsForOrderItems($orderItemIds);
        $customBiselByOrderItem = $this->customBiselMap($orderItemIds);

        $orderCollection->transform(function ($order) use ($productDetailsById, $treatmentsByOrderItem, $customBiselByOrderItem) {
            $order->items->transform(function ($item) use ($productDetailsById, $treatmentsByOrderItem, $customBiselByOrderItem) {
                $pid = (int) $item->product_id;
                $orderItemId = (int) $item->id;

                $detail = $productDetailsById->get($pid);
                $customBisel = $customBiselByOrderItem->get($orderItemId);

                $treatments = collect($treatmentsByOrderItem->get($orderItemId, []))
                    ->filter(fn ($row) => !empty($row->treatment_id))
                    ->map(fn ($row) => [
                        'id' => (int) $row->treatment_id,
                        'name' => $row->treatment_name,
                        'code' => $row->treatment_code,
                        'description' => $row->treatment_description,
                        'extra_price' => (float) ($row->extra_price ?? 0),
                    ])
                    ->values();

                if ($detail) {
                    $productModel = new Product();

                    $productModel->forceFill([
                        'id' => (int) $detail->id,
                        'sku' => $detail->sku,
                        'name' => $detail->name,
                        'description' => $detail->description,

                        'category_id' => $detail->category_id ? (int) $detail->category_id : null,
                        'category_code' => $detail->category_code,
                        'category_name' => $detail->category_name,

                        'type' => $detail->type,
                        'brand' => $detail->brand,
                        'model' => $detail->model,
                        'material' => $detail->material,
                        'size' => $detail->size,

                        'buy_price' => $detail->buy_price !== null ? (float) $detail->buy_price : null,
                        'sale_price' => $detail->sale_price !== null ? (float) $detail->sale_price : null,
                        'min_stock' => $detail->min_stock !== null ? (int) $detail->min_stock : null,
                        'max_stock' => $detail->max_stock !== null ? (int) $detail->max_stock : null,

                        'supplier_id' => $detail->supplier_id ? (int) $detail->supplier_id : null,
                        'supplier_name' => $detail->supplier_name,

                        'box_id' => $detail->box_id ? (int) $detail->box_id : null,
                        'box_code' => $detail->box_code,
                        'box_name' => $detail->box_name,

                        'lens_type_id' => $detail->lens_type_id ? (int) $detail->lens_type_id : null,
                        'lens_type_code' => $detail->lens_type_code,
                        'lens_type_name' => $detail->lens_type_name,

                        'material_id' => $detail->material_id ? (int) $detail->material_id : null,
                        'material_name' => $detail->material_name,

                        'sphere' => $detail->sphere !== null ? (float) $detail->sphere : null,
                        'cylinder' => $detail->cylinder !== null ? (float) $detail->cylinder : null,
                        'axis' => $detail->axis !== null ? (int) $detail->axis : null,

                        'is_custom' => (int) ($detail->is_custom ?? 0),
                        'show_in_pos' => (int) ($detail->show_in_pos ?? 1),

                        'imageUrl' => !empty($detail->image_path)
                            ? url("/api/products/{$detail->id}/image")
                            : null,
                    ]);

                    $productModel->exists = true;
                    $productModel->setAttribute('treatments', $treatments);

                    if ($customBisel) {
                        $productModel->setAttribute('custom_bisel', [
                            'reflection' => $customBisel->reflection,
                            'sphere' => $detail->sphere !== null ? (float) $detail->sphere : null,
                            'cylinder' => $detail->cylinder !== null ? (float) $detail->cylinder : null,
                            'axis' => $detail->axis !== null ? (int) $detail->axis : null,
                            'lens_type_id' => $customBisel->lens_type_id ? (int) $customBisel->lens_type_id : null,
                            'lens_type_code' => $customBisel->lens_type_code,
                            'lens_type_name' => $customBisel->lens_type_name,
                            'frame_height' => $customBisel->frame_height !== null ? (float) $customBisel->frame_height : null,
                            'blank_height' => $customBisel->blank_height !== null ? (float) $customBisel->blank_height : null,
                            'observations' => $customBisel->observations,
                        ]);
                    }

                    $item->unsetRelation('product');
                    $item->setRelation('product', $productModel);
                }

                $item->setAttribute('treatments', $treatments);

                if ($customBisel) {
                    $item->setAttribute('custom_bisel', [
                        'reflection' => $customBisel->reflection,
                        'sphere' => $detail && $detail->sphere !== null ? (float) $detail->sphere : null,
                        'cylinder' => $detail && $detail->cylinder !== null ? (float) $detail->cylinder : null,
                        'axis' => $detail && $detail->axis !== null ? (int) $detail->axis : null,
                        'lens_type_id' => $customBisel->lens_type_id ? (int) $customBisel->lens_type_id : null,
                        'lens_type_code' => $customBisel->lens_type_code,
                        'lens_type_name' => $customBisel->lens_type_name,
                        'frame_height' => $customBisel->frame_height !== null ? (float) $customBisel->frame_height : null,
                        'blank_height' => $customBisel->blank_height !== null ? (float) $customBisel->blank_height : null,
                        'observations' => $customBisel->observations,
                    ]);
                }

                return $item;
            });

            return $order;
        });
    }

    private function createCustomBiselProduct(array $itemData): int
    {
        $categoryId = $this->resolveBiselCategoryId();

        $lensTypeId = !empty($itemData['lens_type_id']) ? (int) $itemData['lens_type_id'] : null;
        $reflection = trim((string) ($itemData['reflection'] ?? ''));
        $observations = trim((string) ($itemData['observations'] ?? ''));
        $unitPrice = (float) ($itemData['unit_price'] ?? 0);
        $customName = trim((string) ($itemData['name'] ?? ''));

        $sphere = array_key_exists('sphere', $itemData) && $itemData['sphere'] !== null && $itemData['sphere'] !== ''
            ? (float) $itemData['sphere']
            : null;

        $cylinder = array_key_exists('cylinder', $itemData) && $itemData['cylinder'] !== null && $itemData['cylinder'] !== ''
            ? (float) $itemData['cylinder']
            : null;

        $axis = array_key_exists('axis', $itemData) && $itemData['axis'] !== null && $itemData['axis'] !== ''
            ? (int) $itemData['axis']
            : null;

        $nameParts = [$customName !== '' ? $customName : 'Biselado personalizado'];
        if ($reflection !== '') {
            $nameParts[] = $reflection;
        }

        $payload = [
            'sku' => 'BIS-CUSTOM-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5)),
            'name' => implode(' - ', $nameParts),
            'description' => $observations !== '' ? $observations : 'Biselado personalizado generado desde POS',
            'category_id' => $categoryId,
            'type' => 'bisel_personalizado',
            'material' => null,
            'buy_price' => 0,
            'sale_price' => $unitPrice,
            'min_stock' => 0,
            'max_stock' => null,
            'supplier_id' => null,
            'box_id' => null,
            'lens_type_id' => $lensTypeId,
            'material_id' => null,
            'sphere' => $sphere,
            'cylinder' => $cylinder,
            'axis' => $axis,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        if (DB::getSchemaBuilder()->hasColumn('products', 'brand')) {
            $payload['brand'] = null;
        }
        if (DB::getSchemaBuilder()->hasColumn('products', 'model')) {
            $payload['model'] = null;
        }
        if (DB::getSchemaBuilder()->hasColumn('products', 'size')) {
            $payload['size'] = null;
        }
        if (DB::getSchemaBuilder()->hasColumn('products', 'is_custom')) {
            $payload['is_custom'] = 1;
        }
        if (DB::getSchemaBuilder()->hasColumn('products', 'show_in_pos')) {
            $payload['show_in_pos'] = 0;
        }

        return (int) DB::table('products')->insertGetId($payload);
    }

    private function isCustomProductId(int $productId): bool
    {
        if (!DB::getSchemaBuilder()->hasColumn('products', 'is_custom')) {
            return false;
        }

        $product = DB::table('products')
            ->where('id', $productId)
            ->select('id', 'is_custom')
            ->first();

        return $product && (int) ($product->is_custom ?? 0) === 1;
    }

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
            ->with(['items'])
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

        $this->attachProductDetailsToOrders($orders);

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

            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.axis' => ['nullable', 'integer'],
            'items.*.item_notes' => ['nullable', 'string'],

            'items.*.treatments' => ['nullable', 'array'],
            'items.*.treatments.*' => ['integer', 'exists:treatments,id'],

            'items.*.custom_bisel' => ['nullable', 'boolean'],
            'items.*.reflection' => ['nullable', 'string', 'max:120'],
            'items.*.lens_type_id' => ['nullable', 'integer', 'exists:lens_types,id'],
            'items.*.frame_height' => ['nullable', 'numeric', 'min:0'],
            'items.*.blank_height' => ['nullable', 'numeric', 'min:0'],
            'items.*.observations' => ['nullable', 'string'],
            'items.*.name' => ['nullable', 'string', 'max:190'],

            'items.*.sphere' => ['nullable', 'numeric', 'between:-40,40'],
            'items.*.cylinder' => ['nullable', 'numeric', 'lt:0'],
            'items.*.axis' => ['nullable', 'integer', 'between:1,180'],
        ]);

        return DB::transaction(function () use ($data, $u) {
            $preparedItems = [];
            $subtotal = 0;

            foreach ($data['items'] as $idx => $it) {
                $isCustomBisel = !empty($it['custom_bisel']);

                $qty = (int) ($it['qty'] ?? 1);
                $unitPrice = (float) ($it['unit_price'] ?? 0);
                $treatments = collect($it['treatments'] ?? [])
                    ->map(fn ($v) => (int) $v)
                    ->filter(fn ($v) => $v > 0)
                    ->unique()
                    ->values()
                    ->all();

                if ($isCustomBisel) {
                    if ($qty !== 1) {
                        abort(response()->json([
                            'message' => 'El biselado personalizado solo puede agregarse una vez por renglón.',
                            'errors' => [
                                "items.{$idx}.qty" => ['La cantidad para biselado personalizado debe ser 1.']
                            ]
                        ], 422));
                    }

                    if (empty($it['lens_type_id'])) {
                        abort(response()->json([
                            'message' => 'Falta tipo de lente.',
                            'errors' => [
                                "items.{$idx}.lens_type_id" => ['El tipo de lente es obligatorio para biselado personalizado.']
                            ]
                        ], 422));
                    }

                    if (!isset($it['frame_height']) || !is_numeric($it['frame_height'])) {
                        abort(response()->json([
                            'message' => 'Falta altura de armazón.',
                            'errors' => [
                                "items.{$idx}.frame_height" => ['La altura de armazón es obligatoria.']
                            ]
                        ], 422));
                    }

                    if (!isset($it['blank_height']) || !is_numeric($it['blank_height'])) {
                        abort(response()->json([
                            'message' => 'Falta altura de oblea.',
                            'errors' => [
                                "items.{$idx}.blank_height" => ['La altura de la oblea es obligatoria.']
                            ]
                        ], 422));
                    }

                    $sphere = array_key_exists('sphere', $it) && $it['sphere'] !== null && $it['sphere'] !== ''
                        ? (float) $it['sphere']
                        : null;

                    $cylinder = array_key_exists('cylinder', $it) && $it['cylinder'] !== null && $it['cylinder'] !== ''
                        ? (float) $it['cylinder']
                        : null;

                    $axis = array_key_exists('axis', $it) && $it['axis'] !== null && $it['axis'] !== ''
                        ? (int) $it['axis']
                        : null;

                    if ($cylinder !== null && $cylinder >= 0) {
                        abort(response()->json([
                            'message' => 'Error de validación',
                            'errors' => [
                                "items.{$idx}.cylinder" => ['El cilindro debe ser negativo y no puede ser 0.']
                            ]
                        ], 422));
                    }

                    if ($cylinder !== null && $axis === null) {
                        abort(response()->json([
                            'message' => 'Error de validación',
                            'errors' => [
                                "items.{$idx}.axis" => ['Si capturas cilindro debes capturar el eje.']
                            ]
                        ], 422));
                    }

                    if ($cylinder === null && $axis !== null) {
                        abort(response()->json([
                            'message' => 'Error de validación',
                            'errors' => [
                                "items.{$idx}.cylinder" => ['Si capturas eje debes capturar cilindro.']
                            ]
                        ], 422));
                    }

                    if ($axis !== null && ($axis < 1 || $axis > 180)) {
                        abort(response()->json([
                            'message' => 'Error de validación',
                            'errors' => [
                                "items.{$idx}.axis" => ['El eje debe estar entre 1 y 180.']
                            ]
                        ], 422));
                    }

                    $pid = $this->createCustomBiselProduct($it);
                } else {
                    $pid = (int) ($it['product_id'] ?? 0);

                    if (!$pid) {
                        abort(response()->json([
                            'message' => 'Producto inválido.',
                            'errors' => [
                                "items.{$idx}.product_id" => ['Debes enviar un product_id válido.']
                            ]
                        ], 422));
                    }

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
                        ->where('p.id', $pid)
                        ->whereNull('p.deleted_at')
                        ->select('p.id', 'p.sale_price')
                        ->first();

                    if (!$product) {
                        abort(response()->json([
                            'message' => 'Producto no encontrado',
                            'errors' => [
                                'items' => ["No existe el producto {$pid}"]
                            ]
                        ], 422));
                    }
                }

                $lineTotal = $qty * $unitPrice;
                $subtotal += $lineTotal;

                $preparedItems[] = [
                    'product_id' => $pid,
                    'variant_id' => $it['variant_id'] ?? null,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'axis' => $it['axis'] ?? null,
                    'item_notes' => $it['item_notes'] ?? null,
                    'treatments' => $treatments,
                    'custom_bisel' => $isCustomBisel,
                    'custom_bisel_detail' => $isCustomBisel ? [
                        'reflection' => $it['reflection'] ?? null,
                        'lens_type_id' => $it['lens_type_id'] ?? null,
                        'frame_height' => $it['frame_height'] ?? null,
                        'blank_height' => $it['blank_height'] ?? null,
                        'observations' => $it['observations'] ?? null,
                    ] : null,
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

                if (!empty($it['treatments']) && DB::getSchemaBuilder()->hasTable('order_item_treatments')) {
                    foreach ($it['treatments'] as $treatmentId) {
                        DB::table('order_item_treatments')->insert([
                            'order_item_id' => $orderItem->id,
                            'treatment_id' => $treatmentId,
                            'price' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if (!empty($it['custom_bisel']) && !empty($it['custom_bisel_detail']) && DB::getSchemaBuilder()->hasTable('order_item_custom_bisel')) {
                    DB::table('order_item_custom_bisel')->insert([
                        'order_item_id' => $orderItem->id,
                        'reflection' => $it['custom_bisel_detail']['reflection'] ?? null,
                        'lens_type_id' => !empty($it['custom_bisel_detail']['lens_type_id']) ? (int) $it['custom_bisel_detail']['lens_type_id'] : null,
                        'frame_height' => isset($it['custom_bisel_detail']['frame_height']) ? (float) $it['custom_bisel_detail']['frame_height'] : null,
                        'blank_height' => isset($it['custom_bisel_detail']['blank_height']) ? (float) $it['custom_bisel_detail']['blank_height'] : null,
                        'observations' => $it['custom_bisel_detail']['observations'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if (empty($it['custom_bisel'])) {
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
            }

            $order->load(['items']);

            $this->attachProductDetailsToOrders($order);

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

        $order = Order::with(['items'])->findOrFail($id);

        if ($role === 'optica' && (int) $order->optica_id !== (int) $u->optica_id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $this->attachProductDetailsToOrders($order);

        return response()->json($order);
    }

    public function cancel(Request $request, $id)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if (!in_array($role, ['optica', 'admin'], true)) {
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

            if ($role === 'optica' && $order->process_status !== 'en_proceso') {
                return response()->json([
                    'message' => 'La óptica solo puede cancelar cuando el pedido está en proceso',
                    'errors' => ['process_status' => ['Estado no cancelable para óptica']]
                ], 422);
            }

            if ($role === 'admin' && $order->process_status !== 'revision') {
                return response()->json([
                    'message' => 'Admin solo puede cancelar cuando el pedido está en revisión',
                    'errors' => ['process_status' => ['Estado no cancelable para admin']]
                ], 422);
            }

            if ($order->process_status === 'cancelado') {
                return response()->json([
                    'message' => 'El pedido ya está cancelado'
                ], 422);
            }

            foreach ($order->items as $it) {
                if ($this->isCustomProductId((int) $it->product_id)) {
                    continue;
                }

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

                if (DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                    DB::table('inventory_movements')->insert([
                        'product_id' => $pid,
                        'variant_id' => $it->variant_id,
                        'movement_type' => 'unreserve',
                        'qty' => $qty,
                        'reference_type' => 'order_cancel',
                        'reference_id' => $order->id,
                        'note' => 'Liberación de reserva por cancelación de pedido',
                        'created_by' => $u->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $order->process_status = 'cancelado';
            $order->save();

            return response()->json([
                'ok' => true,
                'message' => 'Pedido cancelado',
                'order_id' => $order->id
            ], 200);
        });
    }

    public function update(Request $request, $id)
    {
        $u = $request->user();
        $role = $u?->role?->name;

        if (!in_array($role, ['admin', 'employee'], true)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'payment_status' => ['nullable', 'in:pendiente,pagado'],
            'process_status' => ['nullable', 'in:en_proceso,listo_para_entregar,entregado,revision,en_preparacion,cancelado'],
        ]);

        return DB::transaction(function () use ($id, $u, $role, $data) {
            $order = Order::query()
                ->whereNull('deleted_at')
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($id);

            $oldPayment = $order->payment_status;
            $oldProcess = $order->process_status;

            $newPayment = array_key_exists('payment_status', $data) ? $data['payment_status'] : $oldPayment;
            $newProcess = array_key_exists('process_status', $data) ? $data['process_status'] : $oldProcess;

            if ($role === 'employee') {
                if ($newPayment !== $oldPayment) {
                    return response()->json([
                        'message' => 'Solo admin puede cambiar el estatus de pago'
                    ], 403);
                }

                if ($oldProcess === 'entregado' && $newProcess !== $oldProcess) {
                    return response()->json([
                        'message' => 'Entregado: solo admin puede moverlo a revisión'
                    ], 403);
                }

                if ($newProcess === 'revision') {
                    return response()->json([
                        'message' => 'Solo admin puede poner el pedido en revisión'
                    ], 403);
                }

                if ($newProcess === 'cancelado') {
                    return response()->json([
                        'message' => 'Solo admin puede cancelar pedidos desde esta acción'
                    ], 403);
                }
            }

            if ($newPayment !== $oldPayment) {
                $order->payment_status = $newPayment;

                if ($newPayment === 'pagado') {
                    $order->paid_at = now();
                } elseif ($newPayment === 'pendiente') {
                    $order->paid_at = null;
                }
            }

            if ($newProcess !== $oldProcess) {
                if ($newProcess === 'entregado' && $oldProcess !== 'entregado') {
                    foreach ($order->items as $it) {
                        if ($this->isCustomProductId((int) $it->product_id)) {
                            continue;
                        }

                        $pid = (int) $it->product_id;
                        $qty = (int) $it->qty;

                        DB::table('inventory')
                            ->where('product_id', $pid)
                            ->lockForUpdate()
                            ->update([
                                'reserved' => DB::raw('GREATEST(reserved - ' . $qty . ', 0)'),
                                'stock' => DB::raw('GREATEST(stock - ' . $qty . ', 0)'),
                                'updated_at' => now(),
                            ]);

                        if (DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                            DB::table('inventory_movements')->insert([
                                'product_id' => $pid,
                                'variant_id' => $it->variant_id,
                                'movement_type' => 'out',
                                'qty' => $qty,
                                'reference_type' => 'order',
                                'reference_id' => $order->id,
                                'note' => 'Salida por pedido entregado',
                                'created_by' => $u->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                if ($oldProcess === 'entregado' && $newProcess !== 'entregado') {
                    foreach ($order->items as $it) {
                        if ($this->isCustomProductId((int) $it->product_id)) {
                            continue;
                        }

                        $pid = (int) $it->product_id;
                        $qty = (int) $it->qty;

                        DB::table('inventory')
                            ->where('product_id', $pid)
                            ->lockForUpdate()
                            ->update([
                                'stock' => DB::raw('stock + ' . $qty),
                                'reserved' => DB::raw('reserved + ' . $qty),
                                'updated_at' => now(),
                            ]);

                        if (DB::getSchemaBuilder()->hasTable('inventory_movements')) {
                            DB::table('inventory_movements')->insert([
                                'product_id' => $pid,
                                'variant_id' => $it->variant_id,
                                'movement_type' => 'adjustment',
                                'qty' => $qty,
                                'reference_type' => 'order',
                                'reference_id' => $order->id,
                                'note' => 'Reverso de entrega por cambio de estatus',
                                'created_by' => $u->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                $order->process_status = $newProcess;
            }

            $order->save();

            return response()->json([
                'ok' => true,
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'paid_at' => $order->paid_at,
                'process_status' => $order->process_status,
            ]);
        });
    }
}