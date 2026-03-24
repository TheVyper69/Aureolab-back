<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductsController extends Controller
{
    private function categoryIdFromRequest(Request $request): int
    {
        $catId = $request->input('category_id') ?? $request->input('categoryId');

        if ($catId) {
            $cat = Category::whereNull('deleted_at')->find($catId);

            if (!$cat) {
                abort(response()->json([
                    'message' => "Categoría inválida: {$catId}",
                    'errors' => ['category_id' => ['Categoría inválida']]
                ], 422));
            }

            return (int) $cat->id;
        }

        $catRaw = $request->input('category');

        if (!$catRaw) {
            abort(response()->json([
                'message' => 'The category field is required.',
                'errors'  => ['category' => ['The category field is required.']]
            ], 422));
        }

        $cat = Category::whereNull('deleted_at')
            ->where(function ($q) use ($catRaw) {
                $q->where('code', $catRaw)
                  ->orWhere('name', $catRaw);
            })
            ->first();

        if (!$cat) {
            abort(response()->json([
                'message' => "Categoría inválida: {$catRaw}",
                'errors' => ['category' => ['Categoría inválida']]
            ], 422));
        }

        return (int) $cat->id;
    }

    private function isLensCategory(int $categoryId): bool
    {
        $cat = Category::whereNull('deleted_at')->find($categoryId);
        $code = strtoupper((string) ($cat?->code ?? ''));

        return in_array($code, ['MICAS', 'LENTES_CONTACTO'], true);
    }

    private function normalizeOpticalFields(array &$data, int $categoryId, ?Product $product = null): void
    {
        $isLens = $this->isLensCategory($categoryId);

        if (!$isLens) {
            $data['supplier_id'] = null;
            $data['box_id'] = null;
            $data['lens_type_id'] = null;
            $data['material_id'] = null;
            $data['sphere'] = null;
            $data['cylinder'] = null;
            $data['axis'] = null;
            return;
        }

        $effectiveCylinder = array_key_exists('cylinder', $data)
            ? $data['cylinder']
            : ($product?->cylinder ?? null);

        $effectiveAxis = array_key_exists('axis', $data)
            ? $data['axis']
            : ($product?->axis ?? null);

        if (!is_null($effectiveCylinder) && $effectiveCylinder >= 0) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'cylinder' => ['El cilindro debe ser negativo y no puede ser 0.']
                ]
            ], 422));
        }

        if (!is_null($effectiveCylinder) && is_null($effectiveAxis)) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'axis' => ['Si capturas cilindro debes capturar el eje.']
                ]
            ], 422));
        }

        if (is_null($effectiveCylinder) && !is_null($effectiveAxis)) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'cylinder' => ['Si capturas eje debes capturar cilindro.']
                ]
            ], 422));
        }

        if (!is_null($effectiveAxis) && ($effectiveAxis < 1 || $effectiveAxis > 180)) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'axis' => ['El eje debe estar entre 1 y 180.']
                ]
            ], 422));
        }
    }

    private function fillImage(Product $p, Request $request): void
    {
        if (!$request->hasFile('image')) {
            return;
        }

        $file = $request->file('image');

        if (!$file || !$file->isValid()) {
            return;
        }

        if (!empty($p->image_path) && Storage::disk('local')->exists($p->image_path)) {
            Storage::disk('local')->delete($p->image_path);
        }

        $ext = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $filename = 'products/' . Str::uuid() . '.' . strtolower($ext);

        Storage::disk('local')->putFileAs(
            'products',
            $file,
            basename($filename)
        );

        $p->image_path = $filename;
        $p->image_filename = $file->getClientOriginalName();
        $p->image_mime = $file->getMimeType();

        if (property_exists($p, 'image_blob') || array_key_exists('image_blob', $p->getAttributes())) {
            $p->image_blob = null;
        }
    }

    private function productResponse(Product $p): array
    {
        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'description' => $p->description,

            'category_id' => $p->category_id,

            'type' => $p->type,
            'material' => $p->material,

            'buyPrice' => (float) $p->buy_price,
            'salePrice' => (float) $p->sale_price,
            'minStock' => (int) $p->min_stock,
            'maxStock' => $p->max_stock !== null ? (int) $p->max_stock : null,

            'supplier_id' => $p->supplier_id,
            'box_id' => $p->box_id,
            'lens_type_id' => $p->lens_type_id,
            'material_id' => $p->material_id,

            'sphere' => $p->sphere !== null ? (float) $p->sphere : null,
            'cylinder' => $p->cylinder !== null ? (float) $p->cylinder : null,
            'axis' => $p->axis !== null ? (int) $p->axis : null,

            'imageUrl' => !empty($p->image_path)
                ? url("/api/products/{$p->id}/image")
                : null,

            'active' => (bool) $p->active,
        ];
    }

    public function index()
    {
        $rows = DB::table('inventory as i')
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
                'p.description',
                'p.type',
                'p.material',
                'p.buy_price',
                'p.sale_price',
                'p.min_stock',
                'p.max_stock',

                'p.category_id',
                'p.supplier_id',
                'p.box_id',
                'p.lens_type_id',
                'p.material_id',
                'p.sphere',
                'p.cylinder',
                'p.axis',

                'p.image_path',
                'p.image_filename',

                'c.code as category_code',
                'c.name as category_name',
            ])
            ->get();

        return response()->json(
            $rows->map(function ($r) {
                $stock = (int) ($r->stock ?? 0);
                $reserved = (int) ($r->reserved ?? 0);
                $available = max(0, $stock - $reserved);

                return [
                    'stock' => $stock,
                    'reserved' => $reserved,
                    'available' => $available,

                    'product' => [
                        'id' => (int) $r->product_id,
                        'sku' => $r->sku,
                        'name' => $r->name,
                        'description' => $r->description,

                        'type' => $r->type,
                        'material' => $r->material,

                        'buyPrice' => (float) ($r->buy_price ?? 0),
                        'salePrice' => (float) ($r->sale_price ?? 0),
                        'minStock' => (int) ($r->min_stock ?? 0),
                        'maxStock' => $r->max_stock !== null ? (int) $r->max_stock : null,

                        'category_id' => $r->category_id ? (int) $r->category_id : null,
                        'supplier_id' => $r->supplier_id ? (int) $r->supplier_id : null,
                        'box_id' => $r->box_id ? (int) $r->box_id : null,
                        'lens_type_id' => $r->lens_type_id ? (int) $r->lens_type_id : null,
                        'material_id' => $r->material_id ? (int) $r->material_id : null,

                        'sphere' => $r->sphere !== null ? (float) $r->sphere : null,
                        'cylinder' => $r->cylinder !== null ? (float) $r->cylinder : null,
                        'axis' => $r->axis !== null ? (int) $r->axis : null,

                        'imageUrl' => $r->image_path
                            ? url("/api/products/{$r->product_id}/image")
                            : null,

                        'category' => $r->category_code ?? $r->category_name ?? null,
                        'category_label' => $r->category_name ?? null,
                    ],
                ];
            })
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],

            'category_id' => ['required', 'integer', 'exists:categories,id'],

            'type' => ['nullable', 'string', 'max:80'],
            'material' => ['nullable', 'string', 'max:80'],

            'buyPrice' => ['required', 'numeric', 'min:0'],
            'salePrice' => ['required', 'numeric', 'min:0'],
            'minStock' => ['nullable', 'integer', 'min:0'],
            'maxStock' => ['nullable', 'integer', 'min:0'],

            'supplier_id'   => ['nullable', 'integer', 'exists:suppliers,id'],
            'box_id'        => ['nullable', 'integer', 'exists:boxes,id'],
            'lens_type_id'  => ['nullable', 'integer', 'exists:lens_types,id'],
            'material_id'   => ['nullable', 'integer', 'exists:materials,id'],

            'sphere'   => ['nullable', 'numeric', 'between:-40,40'],
            'cylinder' => ['nullable', 'numeric', 'lt:0'],
            'axis'     => ['nullable', 'integer', 'between:1,180'],

            'image' => ['nullable', 'image', 'max:15360'],
        ]);

        $this->normalizeOpticalFields($data, (int) $data['category_id']);

        return DB::transaction(function () use ($data, $request) {
            $p = new Product();

            $p->sku = $data['sku'];
            $p->name = $data['name'];
            $p->description = $data['description'] ?? null;

            $p->category_id = (int) $data['category_id'];

            $p->type = $data['type'] ?? null;
            $p->material = $data['material'] ?? null;

            $p->buy_price = (float) $data['buyPrice'];
            $p->sale_price = (float) $data['salePrice'];
            $p->min_stock = (int) ($data['minStock'] ?? 0);
            $p->max_stock = isset($data['maxStock']) ? (int) $data['maxStock'] : null;

            $p->supplier_id = $data['supplier_id'] ?? null;
            $p->box_id = $data['box_id'] ?? null;
            $p->lens_type_id = $data['lens_type_id'] ?? null;
            $p->material_id = $data['material_id'] ?? null;

            $p->sphere = $data['sphere'] ?? null;
            $p->cylinder = $data['cylinder'] ?? null;
            $p->axis = $data['axis'] ?? null;

            $p->active = 1;

            $this->fillImage($p, $request);
            $p->save();

            DB::table('inventory')->insert([
                'product_id' => $p->id,
                'stock' => 0,
                'reserved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'product' => $this->productResponse($p),
            ], 201);
        });
    }

    public function update(Request $request, $id)
    {
        $p = Product::whereNull('deleted_at')->findOrFail($id);

        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($p->id)],
            'name' => ['sometimes', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'type' => ['nullable', 'string', 'max:80'],
            'material' => ['nullable', 'string', 'max:80'],
            'buyPrice' => ['sometimes', 'numeric', 'min:0'],
            'salePrice' => ['sometimes', 'numeric', 'min:0'],
            'minStock' => ['nullable', 'integer', 'min:0'],
            'maxStock' => ['nullable', 'integer', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            'lens_type_id' => ['nullable', 'integer', 'exists:lens_types,id'],
            'material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'sphere' => ['nullable', 'numeric', 'between:-40,40'],
            'cylinder' => ['nullable', 'numeric', 'lt:0'],
            'axis' => ['nullable', 'integer', 'between:1,180'],
            'image' => ['nullable', 'image', 'max:15360'],
        ]);

        $effectiveCategoryId = array_key_exists('category_id', $data)
            ? (int) $data['category_id']
            : (int) $p->category_id;

        $this->normalizeOpticalFields($data, $effectiveCategoryId, $p);

        if (array_key_exists('sku', $data)) $p->sku = $data['sku'];
        if (array_key_exists('name', $data)) $p->name = $data['name'];
        if (array_key_exists('description', $data)) $p->description = $data['description'];
        if (array_key_exists('category_id', $data)) $p->category_id = (int) $data['category_id'];
        if (array_key_exists('type', $data)) $p->type = $data['type'];
        if (array_key_exists('material', $data)) $p->material = $data['material'];
        if (array_key_exists('buyPrice', $data)) $p->buy_price = (float) $data['buyPrice'];
        if (array_key_exists('salePrice', $data)) $p->sale_price = (float) $data['salePrice'];
        if (array_key_exists('minStock', $data)) $p->min_stock = (int) ($data['minStock'] ?? 0);
        if (array_key_exists('maxStock', $data)) $p->max_stock = isset($data['maxStock']) ? (int) $data['maxStock'] : null;
        if (array_key_exists('supplier_id', $data)) $p->supplier_id = $data['supplier_id'] ? (int) $data['supplier_id'] : null;
        if (array_key_exists('box_id', $data)) $p->box_id = $data['box_id'] ? (int) $data['box_id'] : null;
        if (array_key_exists('lens_type_id', $data)) $p->lens_type_id = $data['lens_type_id'] ? (int) $data['lens_type_id'] : null;
        if (array_key_exists('material_id', $data)) $p->material_id = $data['material_id'] ? (int) $data['material_id'] : null;
        if (array_key_exists('sphere', $data)) $p->sphere = isset($data['sphere']) ? (float) $data['sphere'] : null;
        if (array_key_exists('cylinder', $data)) $p->cylinder = isset($data['cylinder']) ? (float) $data['cylinder'] : null;
        if (array_key_exists('axis', $data)) $p->axis = isset($data['axis']) ? (int) $data['axis'] : null;

        if (!$this->isLensCategory((int) $p->category_id)) {
            $p->supplier_id = null;
            $p->box_id = null;
            $p->lens_type_id = null;
            $p->material_id = null;
            $p->sphere = null;
            $p->cylinder = null;
            $p->axis = null;
        }

        $this->fillImage($p, $request);
        $p->save();

        return response()->json([
            'ok' => true,
            'product' => $this->productResponse($p),
        ], 200);
    }

    public function image($id)
    {
        $p = Product::query()
            ->select('id', 'image_path', 'image_mime', 'image_filename')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$p || !$p->image_path || !Storage::disk('local')->exists($p->image_path)) {
            return response()->noContent();
        }

        $mime = $p->image_mime ?: 'image/jpeg';
        $filename = $p->image_filename ?: "product_{$p->id}.jpg";
        $stream = Storage::disk('local')->readStream($p->image_path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function destroy($id)
    {
        $p = Product::whereNull('deleted_at')->findOrFail($id);

        if (!empty($p->image_path) && Storage::disk('local')->exists($p->image_path)) {
            Storage::disk('local')->delete($p->image_path);
        }

        $p->delete();

        return response()->json(['ok' => true]);
    }

    public function addStock(Request $request, $productId)
    {
        $data = $request->validate([
            'qty'  => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = optional($request->user())->id;

        DB::transaction(function () use ($productId, $data, $userId) {
            DB::table('inventory')->updateOrInsert(
                ['product_id' => $productId],
                [
                    'stock' => DB::raw('COALESCE(stock,0)'),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

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

            DB::table('inventory')
                ->where('product_id', $productId)
                ->update([
                    'stock' => DB::raw('stock + ' . (int) $data['qty']),
                    'last_entry_date' => DB::raw('CURDATE()'),
                    'updated_at' => now(),
                ]);
        });

        $row = DB::table('inventory')
            ->select('product_id', 'stock')
            ->where('product_id', $productId)
            ->first();

        return response()->json([
            'ok' => true,
            'product_id' => (int) $productId,
            'stock' => (int) ($row->stock ?? 0),
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

            'category_id' => $p->category_id,
            'category' => $p->category?->code ?? null,
            'category_label' => $p->category?->name ?? null,

            'type' => $p->type,
            'material' => $p->material,

            'buy_price' => $p->buy_price,
            'sale_price' => $p->sale_price,
            'min_stock' => $p->min_stock,
            'max_stock' => $p->max_stock,

            'supplier_id' => $p->supplier_id,
            'box_id' => $p->box_id,
            'lens_type_id' => $p->lens_type_id,
            'material_id' => $p->material_id,

            'sphere' => $p->sphere,
            'cylinder' => $p->cylinder,
            'axis' => $p->axis,

            'imageUrl' => !empty($p->image_path)
                ? url("/api/products/{$p->id}/image")
                : null,

            'active' => (bool) $p->active,
            'has_image' => !empty($p->image_path),
        ]);
    }
}