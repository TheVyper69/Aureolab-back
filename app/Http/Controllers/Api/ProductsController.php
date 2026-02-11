<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    $products = Product::with(['category:id,code,name'])
        ->whereNull('deleted_at')
        ->where('active', 1)
        ->orderBy('name')
        ->get();

    return response()->json(
        $products->map(function($p){
            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'description' => $p->description,

                // ✅ para filtros del POS (tu pos.js usa p.category como string)
                'category' => $p->category?->code ?? $p->category?->name ?? null,
                'category_label' => $p->category?->name ?? null,

                'type' => $p->type,
                'supplier' => $p->supplier,

                // ✅ camelCase para tu front
                'buyPrice' => (float)$p->buy_price,
                'salePrice' => (float)$p->sale_price,
                'minStock' => (int)$p->min_stock,
                'maxStock' => $p->max_stock !== null ? (int)$p->max_stock : null,

                // ✅ imagen (el POS usa p.imageUrl o p.image_url)
                'imageUrl' => $p->image_filename ? url("/api/products/{$p->id}/image") : null,
                'has_image' => !empty($p->image_blob),
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
    // ✅ acepta ambos formatos
    $data = $request->validate([
        'sku' => ['required','string','max:64','unique:products,sku'],
        'name' => ['required','string','max:220'],
        'description' => ['nullable','string'],

        // puede venir category (code) o category_id
        'category' => ['required_without:category_id,categoryId','nullable','string','max:40'],
        'category_id' => ['required_without:category,categoryId','nullable','integer','exists:categories,id'],
        'categoryId' => ['required_without:category,category_id','nullable','integer','exists:categories,id'],


        'type' => ['nullable','string','max:60'],
        'brand' => ['nullable','string','max:90'],
        'model' => ['nullable','string','max:90'],
        'material' => ['nullable','string','max:90'],
        'size' => ['nullable','string','max:40'],

        // camelCase o snake_case
        'buyPrice' => ['nullable','numeric','min:0'],
        'salePrice' => ['nullable','numeric','min:0'],
        'minStock' => ['nullable','integer','min:0'],
        'maxStock' => ['nullable','integer','min:0'],

        'buy_price' => ['nullable','numeric','min:0'],
        'sale_price' => ['nullable','numeric','min:0'],
        'min_stock' => ['nullable','integer','min:0'],
        'max_stock' => ['nullable','integer','min:0'],

        'supplier' => ['nullable','string','max:190'],
        'image' => ['nullable','file','image','max:2048'],
    ]);

    $p = new Product();
    $p->sku = $data['sku'];
    $p->name = $data['name'];
    $p->description = $data['description'] ?? null;

    // ✅ category_id desde cualquiera de los dos formatos
    $p->category_id = $this->categoryIdFromRequest($request);

    $p->type = $data['type'] ?? null;
    $p->brand = $data['brand'] ?? null;
    $p->model = $data['model'] ?? null;
    $p->material = $data['material'] ?? null;
    $p->size = $data['size'] ?? null;

    // ✅ toma camelCase si existe, si no snake_case
    $buy = $request->input('buyPrice', $request->input('buy_price', 0));
    $sale = $request->input('salePrice', $request->input('sale_price', 0));
    $min = $request->input('minStock', $request->input('min_stock', 0));
    $max = $request->input('maxStock', $request->input('max_stock', null));

    $p->buy_price = (float) $buy;
    $p->sale_price = (float) $sale;
    $p->min_stock = (int) $min;
    $p->max_stock = ($max !== null && $max !== '') ? (int)$max : null;

    $p->active = 1;

    $this->fillImage($p, $request);
    $p->save();

    Inventory::firstOrCreate(
        ['product_id' => $p->id],
        ['stock' => 0]
    );

    return response()->json(['ok'=>true,'id'=>$p->id], 201);
}


    public function update(Request $request, $id)
{
    $product = Product::findOrFail($id);

    $request->validate([
        'sku' => ['nullable','string','max:64', 'unique:products,sku,'.$product->id],
        'name' => ['nullable','string','max:220'],

        // puede venir category o category_id
        'category' => ['required_without:category_id,categoryId','nullable','string','max:40'],
        'category_id' => ['required_without:category,categoryId','nullable','integer','exists:categories,id'],
        'categoryId' => ['required_without:category,category_id','nullable','integer','exists:categories,id'],


        'description' => ['nullable','string'],
        'supplier' => ['nullable','string','max:190'],

        // camelCase o snake_case
        'buyPrice' => ['nullable','numeric','min:0'],
        'salePrice' => ['nullable','numeric','min:0'],
        'minStock' => ['nullable','integer','min:0'],
        'maxStock' => ['nullable','integer','min:0'],

        'buy_price' => ['nullable','numeric','min:0'],
        'sale_price' => ['nullable','numeric','min:0'],
        'min_stock' => ['nullable','integer','min:0'],
        'max_stock' => ['nullable','integer','min:0'],

        'image' => ['nullable','file','image','max:2048'],
    ]);

    // ✅ category_id desde cualquiera
    $catId = $this->categoryIdFromRequest($request);

    $buy = $request->input('buyPrice', $request->input('buy_price', 0));
    $sale = $request->input('salePrice', $request->input('sale_price', 0));
    $min = $request->input('minStock', $request->input('min_stock', 0));
    $max = $request->input('maxStock', $request->input('max_stock', null));

    $data = [
        'sku' => $request->input('sku'),
        'name' => $request->input('name'),
        'description' => $request->input('description'),
        'category_id' => $catId,

        'buy_price' => (float)$buy,
        'sale_price' => (float)$sale,
        'min_stock' => (int)$min,
        'max_stock' => ($max !== null && $max !== '') ? (int)$max : null,
    ];

    // ✅ Imagen (si llegó)
    if($request->hasFile('image')){
        $file = $request->file('image');
        if($file && $file->isValid()){
            $data['image_filename'] = $file->getClientOriginalName();
            $data['image_mime'] = $file->getMimeType();
            $data['image_blob'] = file_get_contents($file->getRealPath());
        }
    }

    $product->update($data);

    return response()->json(['ok'=>true, 'id'=>$product->id]);
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
