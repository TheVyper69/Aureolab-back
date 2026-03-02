<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class ProductsController extends Controller
{
    private function categoryIdFromRequest(Request $request): int
{
    // ✅ 1) category_id (snake) o categoryId (camel)
    $catId = $request->input('category_id') ?? $request->input('categoryId');
    if ($catId) {
        $cat = Category::whereNull('deleted_at')->find($catId);
        if(!$cat){
            abort(response()->json([
                'message' => "Categoría inválida: {$catId}",
                'errors' => ['category_id' => ['Categoría inválida']]
            ], 422));
        }
        return (int)$cat->id;
    }

    // ✅ 2) category (code o name)
    $catRaw = $request->input('category');
    if(!$catRaw){
        abort(response()->json([
            'message' => 'The category field is required.',
            'errors'  => ['category' => ['The category field is required.']]
        ], 422));
    }

    $cat = Category::whereNull('deleted_at')
        ->where('code', $catRaw)
        ->orWhere('name', $catRaw)
        ->first();

    if(!$cat){
        abort(response()->json([
            'message' => "Categoría inválida: {$catRaw}",
            'errors' => ['category' => ['Categoría inválida']]
        ], 422));
    }

    return (int)$cat->id;
}

    public function index()
    {
        $rows = \DB::table('inventory as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')
            ->where('p.active', 1)
            ->orderBy('p.name')
            ->select([
                'i.product_id',
                'i.stock',
                'i.reserved',
                'p.id',
                'p.sku',
                'p.name',
                'p.type',
                'p.sale_price',
                'p.category_id',
                'c.code as category_code',
                'c.name as category_name',
            ])
            ->get();

        return response()->json(
            $rows->map(function($r){
                $stock = (int)($r->stock ?? 0);
                $reserved = (int)($r->reserved ?? 0);
                $available = max(0, $stock - $reserved);

                return [
                    // tu POS ya acepta r.product o r
                    'stock' => $stock,
                    'reserved' => $reserved,
                    'available' => $available,

                    'product' => [
                        'id' => (int)$r->product_id,
                        'sku' => $r->sku,
                        'name' => $r->name,
                        'type' => $r->type,

                        // para UI del POS
                        'category' => $r->category_code ?? $r->category_name ?? null,
                        'category_label' => $r->category_name ?? null,

                        'salePrice' => (float)($r->sale_price ?? 0),
                    ],
                ];
            })
        );
    }

    private function fillImage(Product $p, Request $request): void
    {
        if(!$request->hasFile('image')) return;

        $file = $request->file('image');
        if(!$file->isValid()) return;

        $p->image_filename = $file->getClientOriginalName();
        $p->image_mime = $file->getMimeType();
        $p->image_blob = file_get_contents($file->getRealPath());
    }

    public function store(Request $request)
    {
        // ✅ FORZAR JSON SIEMPRE (evita redirects silenciosos)
        $request->headers->set('Accept', 'application/json');

        // Log de entrada para confirmar que sí pega aquí
        Log::info('[ProductsController@store] HIT', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'accept' => $request->header('Accept'),
            'user_id' => optional($request->user())->id,
            'payload' => $request->all(),
        ]);

        $data = $request->validate([
            'sku' => ['required','string','max:80','unique:products,sku'],
            'name' => ['required','string','max:190'],
            'description' => ['nullable','string'],

            'category_id' => ['required','integer','exists:categories,id'],

            // legacy
            'type' => ['nullable','string','max:80'],
            'material' => ['nullable','string','max:80'],

            'buyPrice' => ['required','numeric','min:0'],
            'salePrice' => ['required','numeric','min:0'],
            'minStock' => ['nullable','integer','min:0'],
            'maxStock' => ['nullable','integer','min:0'],

            // FKs nuevas
            'supplier_id'   => ['nullable','integer','exists:suppliers,id'],
            'box_id'        => ['nullable','integer','exists:boxes,id'],
            'lens_type_id'  => ['nullable','integer','exists:lens_types,id'],
            'material_id'   => ['nullable','integer','exists:materials,id'],
            'treatment_id'  => ['nullable','integer','exists:treatments,id'],

            // esfera/cilindro
            'sphere'   => ['nullable','numeric'],
            'cylinder' => ['nullable','numeric','max:0'],
        ]);

        try {
            return DB::transaction(function () use ($data) {

                $p = new \App\Models\Product();
                $p->sku = $data['sku'];
                $p->name = $data['name'];
                $p->description = $data['description'] ?? null;

                $p->category_id = (int)$data['category_id'];

                // legacy
                $p->type = $data['type'] ?? null;
                $p->material = $data['material'] ?? null;

                $p->buy_price = (float)$data['buyPrice'];
                $p->sale_price = (float)$data['salePrice'];
                $p->min_stock = (int)($data['minStock'] ?? 0);
                $p->max_stock = array_key_exists('maxStock', $data) ? (int)$data['maxStock'] : null;

                // FKs
                $p->supplier_id  = array_key_exists('supplier_id', $data) ? (int)$data['supplier_id'] : null;
                $p->box_id       = array_key_exists('box_id', $data) ? (int)$data['box_id'] : null;
                $p->lens_type_id = array_key_exists('lens_type_id', $data) ? (int)$data['lens_type_id'] : null;
                $p->material_id  = array_key_exists('material_id', $data) ? (int)$data['material_id'] : null;
                $p->treatment_id = array_key_exists('treatment_id', $data) ? (int)$data['treatment_id'] : null;

                $p->sphere = array_key_exists('sphere', $data) ? (float)$data['sphere'] : null;
                $p->cylinder = array_key_exists('cylinder', $data) ? (float)$data['cylinder'] : null;

                $p->active = 1;

                // ✅ captura retorno de save
                $ok = $p->save();

                // Si save devolvió false o no hay id, no lo dejes “pasar”
                if(!$ok || !$p->id){
                    // fuerza rollback
                    throw new \RuntimeException('save() no insertó (ok=false o id=null)');
                }

                // ✅ si quieres crear inventario por default al crear producto (muy recomendable)
                // crea inventory si no existe
                $existsInv = DB::table('inventory')->where('product_id', $p->id)->exists();
                if(!$existsInv){
                    DB::table('inventory')->insert([
                        'product_id' => $p->id,
                        'stock' => 0,
                        'reserved' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return response()->json([
                    'ok' => true,
                    'product' => $p,
                ], 201);
            });

        } catch (QueryException $e) {
            // ✅ error real de SQL (incluye triggers SIGNAL 45000)
            Log::error('[ProductsController@store] SQL ERROR', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'code' => $e->getCode(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error SQL al guardar producto',
                'sql_state' => $e->getCode(),
                'sql_message' => $e->getMessage(),
                // OJO: solo expón sql/bindings en local
                'sql' => app()->environment('local') ? $e->getSql() : null,
                'bindings' => app()->environment('local') ? $e->getBindings() : null,
            ], 500);

        } catch (\Throwable $e) {
            Log::error('[ProductsController@store] ERROR', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error al guardar producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function update(Request $request, $id)
    {
        $p = \App\Models\Product::query()->whereNull('deleted_at')->findOrFail($id);

        $data = $request->validate([
            'sku' => ['sometimes','string','max:80',"unique:products,sku,{$p->id}"],
            'name' => ['sometimes','string','max:190'],
            'description' => ['nullable','string'],

            'category_id' => ['sometimes','integer','exists:categories,id'],

            'type' => ['nullable','string','max:80'],      // legacy
            'material' => ['nullable','string','max:80'],  // legacy

            'buyPrice' => ['sometimes','numeric','min:0'],
            'salePrice' => ['sometimes','numeric','min:0'],
            'minStock' => ['nullable','integer','min:0'],
            'maxStock' => ['nullable','integer','min:0'],

            'supplier_id'   => ['nullable','integer','exists:suppliers,id'],
            'box_id'        => ['nullable','integer','exists:boxes,id'],
            'lens_type_id'  => ['nullable','integer','exists:lens_types,id'],
            'material_id'   => ['nullable','integer','exists:materials,id'],
            'treatment_id'  => ['nullable','integer','exists:treatments,id'],

            'sphere'   => ['nullable','numeric'],
            'cylinder' => ['nullable','numeric','max:0'],
        ]);

        if(array_key_exists('sku',$data)) $p->sku = $data['sku'];
        if(array_key_exists('name',$data)) $p->name = $data['name'];
        if(array_key_exists('description',$data)) $p->description = $data['description'];

        if(array_key_exists('category_id',$data)) $p->category_id = (int)$data['category_id'];

        if(array_key_exists('type',$data)) $p->type = $data['type'];
        if(array_key_exists('material',$data)) $p->material = $data['material'];

        if(array_key_exists('buyPrice',$data)) $p->buy_price = (float)$data['buyPrice'];
        if(array_key_exists('salePrice',$data)) $p->sale_price = (float)$data['salePrice'];

        if(array_key_exists('minStock',$data)) $p->min_stock = (int)($data['minStock'] ?? 0);
        if(array_key_exists('maxStock',$data)) $p->max_stock = isset($data['maxStock']) ? (int)$data['maxStock'] : null;

        // ✅ FKs
        if(array_key_exists('supplier_id',$data)) $p->supplier_id = $data['supplier_id'] ? (int)$data['supplier_id'] : null;
        if(array_key_exists('box_id',$data)) $p->box_id = $data['box_id'] ? (int)$data['box_id'] : null;
        if(array_key_exists('lens_type_id',$data)) $p->lens_type_id = $data['lens_type_id'] ? (int)$data['lens_type_id'] : null;
        if(array_key_exists('material_id',$data)) $p->material_id = $data['material_id'] ? (int)$data['material_id'] : null;
        if(array_key_exists('treatment_id',$data)) $p->treatment_id = $data['treatment_id'] ? (int)$data['treatment_id'] : null;

        // ✅ esfera/cilindro
        if(array_key_exists('sphere',$data)) $p->sphere = isset($data['sphere']) ? (float)$data['sphere'] : null;
        if(array_key_exists('cylinder',$data)) $p->cylinder = isset($data['cylinder']) ? (float)$data['cylinder'] : null;

        $p->save();

        return response()->json($p, 200);
    }


    public function image($id)
{
    $p = Product::query()
        ->select('id','image_mime','image_blob','image_filename')
        ->where('id', $id)
        ->whereNull('deleted_at')
        ->first();

    if (!$p || !$p->image_blob) {
        return response()->noContent(); // 204
    }

    $blob = $p->image_blob;

    // ✅ Si el blob viene como recurso/stream, conviértelo a string
    if (is_resource($blob)) {
        $blob = stream_get_contents($blob);
    }

    // ✅ Por si llega como objeto Stringable, fuerza string
    if (!is_string($blob)) {
        $blob = (string) $blob;
    }

    $mime = $p->image_mime ?: 'image/jpeg';
    $filename = $p->image_filename ?: "product_{$p->id}.jpg";

    return response($blob, 200, [
        'Content-Type' => $mime,
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ]);
}

    public function destroy($id)
    {
        $p = Product::whereNull('deleted_at')->findOrFail($id);
        $p->delete();
        return response()->json(['ok'=>true]);
    }
    public function addStock(Request $request, $productId)
    {
        $data = $request->validate([
            'qty'  => ['required','integer','min:1'],
            'note' => ['nullable','string','max:255'],
        ]);

        $userId = optional($request->user())->id;

        DB::transaction(function () use ($productId, $data, $userId) {

            // asegurar fila en inventory
            DB::table('inventory')->updateOrInsert(
                ['product_id' => $productId],
                [
                    'stock' => DB::raw('COALESCE(stock,0)'),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // movimiento (auditoría)
            DB::table('inventory_movements')->insert([
                'product_id' => $productId,
                'variant_id' => null,
                'movement_type' => 'in',
                'qty' => $data['qty'],
                'reference_type' => 'manual',
                'reference_id' => null,
                'note' => $data['note'] ?? 'Entrada manual',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // aumentar stock
            DB::table('inventory')
                ->where('product_id', $productId)
                ->update([
                    'stock' => DB::raw('stock + '.(int)$data['qty']),
                    'last_entry_date' => DB::raw('CURDATE()'),
                    'updated_at' => now(),
                ]);
        });

        $row = DB::table('inventory')->select('product_id','stock')->where('product_id',$productId)->first();

        return response()->json([
            'ok' => true,
            'product_id' => (int)$productId,
            'stock' => (int)($row->stock ?? 0),
        ]);
    }

    public function show($id)
{
    $p = Product::with('category')
        ->whereNull('deleted_at')
        ->findOrFail($id);

    return response()->json([
        'id' => $p->id,
        'sku' => $p->sku,
        'name' => $p->name,
        'description' => $p->description,
        'category' => $p->category?->code ?? null,   // si manejas code
        'category_label' => $p->category?->name ?? null,
        'buy_price' => $p->buy_price,
        'sale_price' => $p->sale_price,
        'min_stock' => $p->min_stock,
        'max_stock' => $p->max_stock,
        'active' => (bool)$p->active,
        'has_image' => !empty($p->image_blob),
    ]);
}
}
