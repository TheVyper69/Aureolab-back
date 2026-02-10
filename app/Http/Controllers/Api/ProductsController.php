<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
    private function categoryIdFromRequest(Request $request): int
    {
        // Tu front manda "category" como CODE (MICAS, BISEL, etc.)
        $catCode = $request->input('category');
        $cat = Category::whereNull('deleted_at')->where('code', $catCode)->first();

        if(!$cat){
            abort(response()->json(['message'=>"Categoría inválida: {$catCode}"], 422));
        }
        return (int)$cat->id;
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
        $data = $request->validate([
            'sku' => ['required','string','max:64','unique:products,sku'],
            'name' => ['required','string','max:220'],
            'description' => ['nullable','string'],

            'category' => ['required','string','max:40'], // code
            'type' => ['nullable','string','max:60'],
            'brand' => ['nullable','string','max:90'],
            'model' => ['nullable','string','max:90'],
            'material' => ['nullable','string','max:90'],
            'size' => ['nullable','string','max:40'],

            'buyPrice' => ['nullable','numeric','min:0'],
            'salePrice' => ['nullable','numeric','min:0'],
            'minStock' => ['nullable','integer','min:0'],
            'maxStock' => ['nullable','integer','min:0'],

            'supplier' => ['nullable','string','max:190'], // si luego lo conviertes a supplier_id, lo cambias
        ]);

        $p = new Product();
        $p->sku = $data['sku'];
        $p->name = $data['name'];
        $p->description = $data['description'] ?? null;

        $p->category_id = $this->categoryIdFromRequest($request);

        $p->type = $data['type'] ?? null;
        $p->brand = $data['brand'] ?? null;
        $p->model = $data['model'] ?? null;
        $p->material = $data['material'] ?? null;
        $p->size = $data['size'] ?? null;

        $p->buy_price = (float)($data['buyPrice'] ?? 0);
        $p->sale_price = (float)($data['salePrice'] ?? 0);
        $p->min_stock = (int)($data['minStock'] ?? 0);
        $p->max_stock = $data['maxStock'] !== null ? (int)$data['maxStock'] : null;

        $p->active = 1;

        $this->fillImage($p, $request);
        $p->save();

        // crea fila en inventory por default si no existe
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
        'sku' => ['required','string','max:64', 'unique:products,sku,'.$product->id],
        'name' => ['required','string','max:220'],
        'category' => ['required'], // viene como code o name
        'buyPrice' => ['nullable','numeric','min:0'],
        'salePrice' => ['nullable','numeric','min:0'],
        'minStock' => ['nullable','integer','min:0'],
        'maxStock' => ['nullable','integer','min:0'],
        'description' => ['nullable','string'],
        'supplier' => ['nullable','string'],
        'image' => ['nullable','file','image','max:2048'],
    ]);

    // category -> category_id
    $cat = Category::where('code', $request->category)
        ->orWhere('name', $request->category)
        ->first();

    if(!$cat){
        return response()->json([
            'message' => 'Categoría inválida',
            'errors' => ['category' => ['Categoría inválida']]
        ], 422);
    }

    $data = [
        'sku' => $request->sku,
        'name' => $request->name,
        'description' => $request->description,
        'category_id' => $cat->id,

        'buy_price' => $request->buyPrice ?? 0,
        'sale_price' => $request->salePrice ?? 0,
        'min_stock' => $request->minStock ?? 0,
        'max_stock' => $request->maxStock,

        // si luego haces supplier_id real, aquí lo mapeas
    ];

    // Imagen (si llegó)
    if($request->hasFile('image')){
        $file = $request->file('image');
        $data['image_filename'] = $file->getClientOriginalName();
        $data['image_mime'] = $file->getMimeType();
        $data['image_blob'] = file_get_contents($file->getRealPath());
    }

    $product->update($data);

    return response()->json(['ok'=>true]);
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
}
