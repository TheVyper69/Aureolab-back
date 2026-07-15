<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private function hasProductColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::hasColumn('products', $column);
        }

        return $cache[$column];
    }

    private function hasCategoryColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::hasColumn('categories', $column);
        }

        return $cache[$column];
    }

    private function applyVisibleProductsScope($query)
    {
        $query->whereNull('p.deleted_at')
              ->where('p.active', 1);

        if ($this->hasProductColumn('show_in_pos')) {
            $query->where('p.show_in_pos', 1);
        }

        if ($this->hasProductColumn('is_custom_order')) {
            $query->where('p.is_custom_order', 0);
        }

        if ($this->hasProductColumn('is_temporary_order_item')) {
            $query->where('p.is_temporary_order_item', 0);
        }

        return $query;
    }

    private function applyVisibleProductsScopeModel($query)
    {
        $query->whereNull('deleted_at')
              ->where('active', 1);

        if ($this->hasProductColumn('show_in_pos')) {
            $query->where('show_in_pos', 1);
        }

        if ($this->hasProductColumn('is_custom_order')) {
            $query->where('is_custom_order', 0);
        }

        if ($this->hasProductColumn('is_temporary_order_item')) {
            $query->where('is_temporary_order_item', 0);
        }

        return $query;
    }

    private function getCategory(int $categoryId): ?Category
    {
        return Category::whereNull('deleted_at')->find($categoryId);
    }

    private function isLensCategory(int $categoryId): bool
    {
        $cat = $this->getCategory($categoryId);

        if (!$cat) return false;

        if ($this->hasCategoryColumn('is_mica') && (int) ($cat->is_mica ?? 0) === 1) {
            return true;
        }

        $code = strtoupper((string) ($cat->code ?? ''));

        return in_array($code, ['MICAS', 'LENTES_CONTACTO'], true)
            || str_starts_with($code, 'MICA_')
            || str_starts_with($code, 'MICA-');
    }

    private function isMicasCategory(int $categoryId): bool
    {
        $cat = $this->getCategory($categoryId);

        if (!$cat) return false;

        if ($this->hasCategoryColumn('is_mica') && (int) ($cat->is_mica ?? 0) === 1) {
            return true;
        }

        $code = strtoupper((string) ($cat->code ?? ''));

        return $code === 'MICAS'
            || str_starts_with($code, 'MICA_')
            || str_starts_with($code, 'MICA-');
    }

    private function isQuarterStep($value): bool
    {
        if ($value === null || $value === '') return true;

        $n = (float) $value;
        $scaled = round($n * 100);

        return $scaled % 25 === 0;
    }

    private function opticalNumber($value): float
    {
        return round((float) $value, 2);
    }

    private function formatOpticalValue($value): string
    {
        $n = $this->opticalNumber($value);

        if (abs($n) < 0.00001) {
            $n = 0.00;
        }

        return number_format($n, 2, '.', '');
    }

    private function normalizeSkuPart($value): string
    {
        $text = strtoupper((string) $value);
        $text = Str::ascii($text);
        $text = preg_replace('/[^A-Z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text ?: 'PRODUCTO';
    }

    private function buildMicaSku(Category $cat, float $sphere, float $cylinder): string
    {
        $base = $this->normalizeSkuPart($cat->code ?: $cat->name ?: 'MICA');

        $s = $this->formatOpticalValue($sphere);
        $c = $this->formatOpticalValue($cylinder);

        $sPart = str_replace(['-', '.'], ['N', 'P'], $s);
        $cPart = str_replace(['-', '.'], ['N', 'P'], $c);

        return "{$base}-ESF{$sPart}-CIL{$cPart}";
    }

    private function makeUniqueSku(string $baseSku): string
    {
        $sku = $baseSku;
        $i = 2;

        while (
            Product::query()
                ->where('sku', $sku)
                ->when($this->hasProductColumn('deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->exists()
        ) {
            $sku = "{$baseSku}-{$i}";
            $i++;
        }

        return $sku;
    }

    private function buildMicaName(Category $cat, float $sphere, float $cylinder): string
    {
        $name = $cat->name ?: 'Mica';

        return "{$name} ESF {$this->formatOpticalValue($sphere)} CIL {$this->formatOpticalValue($cylinder)}";
    }

    private function normalizeOpticalFields(array &$data, int $categoryId, ?Product $product = null): void
    {
        $isLens = $this->isLensCategory($categoryId);
        $isMica = $this->isMicasCategory($categoryId);

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

        if (array_key_exists('sphere', $data) && $data['sphere'] !== null && $data['sphere'] !== '') {
            if (!$this->isQuarterStep($data['sphere'])) {
                abort(response()->json([
                    'message' => 'Error de validación',
                    'errors' => [
                        'sphere' => ['La esfera debe ir en incrementos de 0.25.']
                    ]
                ], 422));
            }

            $data['sphere'] = $this->opticalNumber($data['sphere']);
        }

        if (array_key_exists('cylinder', $data) && $data['cylinder'] !== null && $data['cylinder'] !== '') {
            if (!$this->isQuarterStep($data['cylinder'])) {
                abort(response()->json([
                    'message' => 'Error de validación',
                    'errors' => [
                        'cylinder' => ['El cilindro debe ir en incrementos de 0.25.']
                    ]
                ], 422));
            }

            $data['cylinder'] = $this->opticalNumber($data['cylinder']);
        }

        $effectiveCylinder = array_key_exists('cylinder', $data)
            ? $data['cylinder']
            : ($product?->cylinder ?? null);

        $effectiveAxis = array_key_exists('axis', $data)
            ? $data['axis']
            : ($product?->axis ?? null);

        if (!is_null($effectiveCylinder) && (float) $effectiveCylinder > 0) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'cylinder' => ['El cilindro no puede ser positivo. Debe ser 0 o negativo.']
                ]
            ], 422));
        }

        if (!$isMica) {
            if (!is_null($effectiveCylinder) && (float) $effectiveCylinder >= 0) {
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
        } else {
            $data['axis'] = null;
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

    private function normalizeTreatments(array $data, int $categoryId): array
    {
        $treatments = collect($data['treatments'] ?? [])
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();

        if (!$this->isMicasCategory($categoryId) && !empty($treatments)) {
            abort(response()->json([
                'message' => 'Solo las micas pueden llevar tratamientos',
                'errors' => [
                    'treatments' => ['Solo los productos de categoría mica pueden llevar tratamientos.']
                ]
            ], 422));
        }

        return $treatments;
    }

    private function syncTreatments(int $productId, array $treatmentIds): void
    {
        DB::table('product_treatments')
            ->where('product_id', $productId)
            ->delete();

        if (empty($treatmentIds)) {
            return;
        }

        $now = now();

        $rows = collect($treatmentIds)->map(function ($treatmentId) use ($productId, $now) {
            return [
                'product_id' => $productId,
                'treatment_id' => (int) $treatmentId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        DB::table('product_treatments')->insert($rows);
    }

    private function getTreatmentsForProducts(array $productIds)
    {
        if (empty($productIds)) {
            return collect();
        }

        return DB::table('product_treatments as pt')
            ->join('treatments as t', 't.id', '=', 'pt.treatment_id')
            ->whereIn('pt.product_id', $productIds)
            ->select(
                'pt.product_id',
                't.id as treatment_id',
                't.name as treatment_name',
                't.code as treatment_code'
            )
            ->get()
            ->groupBy('product_id');
    }

    private function mapTreatments($rows): array
    {
        return collect($rows)
            ->map(fn ($row) => [
                'id' => (int) $row->treatment_id,
                'name' => $row->treatment_name,
                'code' => $row->treatment_code,
            ])
            ->values()
            ->all();
    }

    private function getTreatmentsForProduct(int $productId): array
    {
        $rows = DB::table('product_treatments as pt')
            ->join('treatments as t', 't.id', '=', 'pt.treatment_id')
            ->where('pt.product_id', $productId)
            ->select(
                't.id as treatment_id',
                't.name as treatment_name',
                't.code as treatment_code'
            )
            ->get();

        return $this->mapTreatments($rows);
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
        $category = null;

        if ($p->category_id) {
            $category = Category::whereNull('deleted_at')->find($p->category_id);
        }

        $hasOwnImage =
            (!empty($p->image_path)) ||
            (
                $this->hasProductColumn('image_blob') &&
                array_key_exists('image_blob', $p->getAttributes()) &&
                !empty($p->image_blob)
            );

        $hasCategoryImage = false;

        if ($category) {
            $hasCategoryImage =
                (
                    $this->hasCategoryColumn('image_blob') &&
                    array_key_exists('image_blob', $category->getAttributes()) &&
                    !empty($category->image_blob)
                ) ||
                (
                    $this->hasCategoryColumn('image_path') &&
                    !empty($category->image_path)
                );
        }

        $hasImage = $hasOwnImage || $hasCategoryImage;

        $response = [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'description' => $p->description,

            'category_id' => $p->category_id,
            'category' => $category?->code ?? null,
            'category_label' => $category?->name ?? null,
            'category_code' => $category?->code ?? null,
            'category_name' => $category?->name ?? null,
            'category_is_mica' => $category && $this->isMicasCategory((int) $category->id),

            'type' => $p->type,
            'material' => $p->material,

            'buyPrice' => (float) $p->buy_price,
            'salePrice' => (float) $p->sale_price,
            'buy_price' => (float) $p->buy_price,
            'sale_price' => (float) $p->sale_price,

            'minStock' => (int) $p->min_stock,
            'maxStock' => $p->max_stock !== null ? (int) $p->max_stock : null,

            'supplier_id' => $p->supplier_id,
            'box_id' => $p->box_id,
            'lens_type_id' => $p->lens_type_id,
            'material_id' => $p->material_id,

            'sphere' => $p->sphere !== null ? (float) $p->sphere : null,
            'cylinder' => $p->cylinder !== null ? (float) $p->cylinder : null,
            'axis' => $p->axis !== null ? (int) $p->axis : null,

            'treatments' => $this->getTreatmentsForProduct((int) $p->id),

            'imageUrl' => $hasImage ? url("/api/products/{$p->id}/image") : null,
            'image_url' => $hasImage ? url("/api/products/{$p->id}/image") : null,
            'has_image' => $hasImage,
            'has_own_image' => $hasOwnImage,
            'has_category_image' => $hasCategoryImage,

            'active' => (bool) $p->active,
        ];

        if ($this->hasProductColumn('show_in_pos')) {
            $response['show_in_pos'] = (bool) ($p->show_in_pos ?? true);
        }

        if ($this->hasProductColumn('is_custom_order')) {
            $response['is_custom_order'] = (bool) ($p->is_custom_order ?? false);
        }

        if ($this->hasProductColumn('is_temporary_order_item')) {
            $response['is_temporary_order_item'] = (bool) ($p->is_temporary_order_item ?? false);
        }

        return $response;
    }

    private function shouldBulkGenerateMicas(Request $request): bool
    {
        return $request->boolean('bulk_mica')
            || $request->boolean('bulkMica')
            || $request->boolean('generate_micas')
            || $request->boolean('generateMicas')
            || (
                $request->has('sphere_min') &&
                $request->has('sphere_max') &&
                $request->has('cylinder_max')
            );
    }

    private function validateBulkMicaRequest(Request $request): array
    {
        $rules = [
            'category_id' => ['required', 'integer', 'exists:categories,id'],

            'name' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string'],

            'type' => ['nullable', 'string', 'max:80'],
            'material' => ['nullable', 'string', 'max:80'],

            'minStock' => ['nullable', 'integer', 'min:0'],
            'maxStock' => ['nullable', 'integer', 'min:0'],
            'initial_stock' => ['nullable', 'integer', 'min:0'],
            'initialStock' => ['nullable', 'integer', 'min:0'],

            'supplier_id'   => ['nullable', 'integer', 'exists:suppliers,id'],
            'box_id'        => ['nullable', 'integer', 'exists:boxes,id'],
            'lens_type_id'  => ['nullable', 'integer', 'exists:lens_types,id'],
            'material_id'   => ['nullable', 'integer', 'exists:materials,id'],

            'sphere_min' => ['required', 'numeric', 'between:-40,40'],
            'sphereMax' => ['nullable', 'numeric', 'between:-40,40'],
            'sphere_max' => ['required_without:sphereMax', 'numeric', 'between:-40,40'],

            'cylinder_max' => ['required', 'numeric', 'between:-40,0'],
            'cylinderMax' => ['nullable', 'numeric', 'between:-40,0'],

            'treatments' => ['nullable', 'array'],
            'treatments.*' => ['integer', 'exists:treatments,id'],

            'skip_existing' => ['nullable', 'boolean'],
            'skipExisting' => ['nullable', 'boolean'],
        ];

        if ($this->hasProductColumn('show_in_pos')) {
            $rules['show_in_pos'] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);

        $data['sphere_min'] = (float) $data['sphere_min'];
        $data['sphere_max'] = array_key_exists('sphere_max', $data)
            ? (float) $data['sphere_max']
            : (float) ($data['sphereMax'] ?? 0);

        $data['cylinder_max'] = array_key_exists('cylinder_max', $data)
            ? (float) $data['cylinder_max']
            : (float) ($data['cylinderMax'] ?? 0);

        if (!$this->isQuarterStep($data['sphere_min'])) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'sphere_min' => ['La esfera mínima debe ir en incrementos de 0.25.']
                ]
            ], 422));
        }

        if (!$this->isQuarterStep($data['sphere_max'])) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'sphere_max' => ['La esfera máxima debe ir en incrementos de 0.25.']
                ]
            ], 422));
        }

        if (!$this->isQuarterStep($data['cylinder_max'])) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'cylinder_max' => ['El cilindro máximo debe ir en incrementos de 0.25.']
                ]
            ], 422));
        }

        if ($data['sphere_min'] > $data['sphere_max']) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'sphere_min' => ['La esfera mínima no puede ser mayor que la esfera máxima.']
                ]
            ], 422));
        }

        if ($data['cylinder_max'] > 0) {
            abort(response()->json([
                'message' => 'Error de validación',
                'errors' => [
                    'cylinder_max' => ['El cilindro máximo no puede ser positivo.']
                ]
            ], 422));
        }

        $cat = $this->getCategory((int) $data['category_id']);

        if (!$cat || !$this->isMicasCategory((int) $cat->id)) {
            abort(response()->json([
                'message' => 'La categoría seleccionada no está marcada como mica.',
                'errors' => [
                    'category_id' => ['La categoría debe tener is_mica = 1.']
                ]
            ], 422));
        }

        return $data;
    }

    private function generateSphereValues(float $min, float $max): array
    {
        $values = [];

        $start = (int) round($min * 100);
        $end = (int) round($max * 100);

        for ($v = $start; $v <= $end; $v += 25) {
            $values[] = round($v / 100, 2);
        }

        return $values;
    }

    private function generateCylinderValues(float $maxNegative): array
    {
        $values = [];

        $end = (int) round($maxNegative * 100);

        for ($v = 0; $v >= $end; $v -= 25) {
            $values[] = round($v / 100, 2);
        }

        return $values;
    }

    private function existingMicaProductQuery(array $data, float $sphere, float $cylinder)
    {
        $query = Product::query()
            ->where('category_id', (int) $data['category_id'])
            ->where('sphere', $sphere)
            ->where('cylinder', $cylinder);

        if ($this->hasProductColumn('deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $query->where(function ($q) use ($data) {
            $q->where('lens_type_id', $data['lens_type_id'] ?? null);
        });

        $query->where(function ($q) use ($data) {
            $q->where('material_id', $data['material_id'] ?? null);
        });

        $query->where(function ($q) use ($data) {
            $q->where('box_id', $data['box_id'] ?? null);
        });

        return $query;
    }

    private function storeBulkMicas(Request $request)
    {
        $data = $this->validateBulkMicaRequest($request);
        $category = $this->getCategory((int) $data['category_id']);

        $treatmentIds = $this->normalizeTreatments($data, (int) $category->id);

        $sphereValues = $this->generateSphereValues(
            (float) $data['sphere_min'],
            (float) $data['sphere_max']
        );

        $cylinderValues = $this->generateCylinderValues(
            (float) $data['cylinder_max']
        );

        $initialStock = (int) ($data['initial_stock'] ?? $data['initialStock'] ?? 0);
        $minStock = (int) ($data['minStock'] ?? 0);
        $maxStock = isset($data['maxStock']) ? (int) $data['maxStock'] : null;

        $skipExisting = $request->boolean('skip_existing', true) || $request->boolean('skipExisting', true);

        return DB::transaction(function () use (
            $request,
            $data,
            $category,
            $treatmentIds,
            $sphereValues,
            $cylinderValues,
            $initialStock,
            $minStock,
            $maxStock,
            $skipExisting
        ) {
            $created = [];
            $skipped = [];
            $errors = [];

            foreach ($cylinderValues as $cylinder) {
                foreach ($sphereValues as $sphere) {
                    $existing = $this->existingMicaProductQuery($data, $sphere, $cylinder)->first();

                    if ($existing && $skipExisting) {
                        $skipped[] = [
                            'id' => (int) $existing->id,
                            'sku' => $existing->sku,
                            'sphere' => $sphere,
                            'cylinder' => $cylinder,
                            'reason' => 'Ya existe producto con esa categoría, esfera, cilindro, tipo de lente, material y caja.',
                        ];

                        continue;
                    }

                    $p = new Product();

                    $baseSku = $this->buildMicaSku($category, $sphere, $cylinder);

                    $p->sku = $existing
                        ? $this->makeUniqueSku($baseSku)
                        : $this->makeUniqueSku($baseSku);

                    $p->name = $this->buildMicaName($category, $sphere, $cylinder);
                    $p->description = $data['description'] ?? null;

                    $p->category_id = (int) $category->id;

                    $p->type = $data['type'] ?? null;
                    $p->material = $data['material'] ?? null;

                    $p->buy_price = $this->hasCategoryColumn('buy_price')
                        ? (float) ($category->buy_price ?? 0)
                        : 0;

                    $p->sale_price = $this->hasCategoryColumn('sale_price')
                        ? (float) ($category->sale_price ?? 0)
                        : 0;

                    $p->min_stock = $minStock;
                    $p->max_stock = $maxStock;

                    $p->supplier_id = $data['supplier_id'] ?? null;
                    $p->box_id = $data['box_id'] ?? null;
                    $p->lens_type_id = $data['lens_type_id'] ?? null;
                    $p->material_id = $data['material_id'] ?? null;

                    $p->sphere = $sphere;
                    $p->cylinder = $cylinder;
                    $p->axis = null;

                    $p->active = 1;

                    if ($this->hasProductColumn('show_in_pos')) {
                        $p->show_in_pos = array_key_exists('show_in_pos', $data)
                            ? (int) ((bool) $data['show_in_pos'])
                            : 1;
                    }

                    if ($this->hasProductColumn('is_custom_order')) {
                        $p->is_custom_order = 0;
                    }

                    if ($this->hasProductColumn('is_temporary_order_item')) {
                        $p->is_temporary_order_item = 0;
                    }

                    $p->save();

                    DB::table('inventory')->insert([
                        'product_id' => $p->id,
                        'stock' => $initialStock,
                        'reserved' => 0,
                        'last_entry_date' => $initialStock > 0 ? now()->toDateString() : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($initialStock > 0) {
                        DB::table('inventory_movements')->insert([
                            'product_id' => $p->id,
                            'variant_id' => null,
                            'movement_type' => 'in',
                            'qty' => $initialStock,
                            'reference_type' => 'manual',
                            'reference_id' => null,
                            'note' => 'Stock inicial por generación masiva de micas',
                            'created_by' => optional($request->user())->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $this->syncTreatments((int) $p->id, $treatmentIds);

                    $created[] = [
                        'id' => (int) $p->id,
                        'sku' => $p->sku,
                        'name' => $p->name,
                        'sphere' => $sphere,
                        'cylinder' => $cylinder,
                        'buyPrice' => (float) $p->buy_price,
                        'salePrice' => (float) $p->sale_price,
                        'stock' => $initialStock,
                    ];
                }
            }

            return response()->json([
                'ok' => true,
                'message' => 'Generación masiva de micas completada.',
                'category' => [
                    'id' => (int) $category->id,
                    'code' => $category->code,
                    'name' => $category->name,
                    'is_mica' => true,
                    'buyPrice' => $this->hasCategoryColumn('buy_price') ? (float) ($category->buy_price ?? 0) : 0,
                    'salePrice' => $this->hasCategoryColumn('sale_price') ? (float) ($category->sale_price ?? 0) : 0,
                ],
                'summary' => [
                    'sphere_min' => $this->formatOpticalValue($data['sphere_min']),
                    'sphere_max' => $this->formatOpticalValue($data['sphere_max']),
                    'cylinder_max' => $this->formatOpticalValue($data['cylinder_max']),
                    'sphere_count' => count($sphereValues),
                    'cylinder_count' => count($cylinderValues),
                    'expected_total' => count($sphereValues) * count($cylinderValues),
                    'created_count' => count($created),
                    'skipped_count' => count($skipped),
                ],
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
            ], 201);
        });
    }

    public function index()
    {
        $query = DB::table('inventory as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id');

        $this->applyVisibleProductsScope($query);

        $select = [
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
        ];

        if ($this->hasCategoryColumn('is_mica')) {
            $select[] = 'c.is_mica as category_is_mica';
        }

        if ($this->hasCategoryColumn('buy_price')) {
            $select[] = 'c.buy_price as category_buy_price';
        }

        if ($this->hasCategoryColumn('sale_price')) {
            $select[] = 'c.sale_price as category_sale_price';
        }

        $rows = $query
            ->orderBy('p.name')
            ->select($select)
            ->get();

        $productIds = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $treatmentsByProduct = $this->getTreatmentsForProducts($productIds);

        return response()->json(
            $rows->map(function ($r) use ($treatmentsByProduct) {
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
                        'buy_price' => (float) ($r->buy_price ?? 0),
                        'sale_price' => (float) ($r->sale_price ?? 0),

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

                        'treatments' => $this->mapTreatments($treatmentsByProduct->get($r->product_id, [])),

                        'imageUrl' => url("/api/products/{$r->product_id}/image"),
                        'image_url' => url("/api/products/{$r->product_id}/image"),
                        'has_image' => true,

                        'category' => $r->category_code ?? $r->category_name ?? null,
                        'category_label' => $r->category_name ?? null,
                        'category_code' => $r->category_code ?? null,
                        'category_name' => $r->category_name ?? null,

                        'category_is_mica' => property_exists($r, 'category_is_mica')
                            ? (bool) $r->category_is_mica
                            : false,

                        'category_buy_price' => property_exists($r, 'category_buy_price')
                            ? (float) ($r->category_buy_price ?? 0)
                            : 0,

                        'category_sale_price' => property_exists($r, 'category_sale_price')
                            ? (float) ($r->category_sale_price ?? 0)
                            : 0,
                    ],
                ];
            })
        );
    }

    public function store(Request $request)
    {
        if ($this->shouldBulkGenerateMicas($request)) {
            return $this->storeBulkMicas($request);
        }

        $rules = [
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string'],

            'category_id' => ['required', 'integer', 'exists:categories,id'],

            'type' => ['nullable', 'string', 'max:80'],
            'material' => ['nullable', 'string', 'max:80'],

            'buyPrice' => ['nullable', 'numeric', 'min:0'],
            'salePrice' => ['nullable', 'numeric', 'min:0'],
            'minStock' => ['nullable', 'integer', 'min:0'],
            'maxStock' => ['nullable', 'integer', 'min:0'],

            'supplier_id'   => ['nullable', 'integer', 'exists:suppliers,id'],
            'box_id'        => ['nullable', 'integer', 'exists:boxes,id'],
            'lens_type_id'  => ['nullable', 'integer', 'exists:lens_types,id'],
            'material_id'   => ['nullable', 'integer', 'exists:materials,id'],

            'sphere'   => ['nullable', 'numeric', 'between:-40,40'],
            'cylinder' => ['nullable', 'numeric', 'between:-40,0'],
            'axis'     => ['nullable', 'integer', 'between:1,180'],

            'treatments' => ['nullable', 'array'],
            'treatments.*' => ['integer', 'exists:treatments,id'],

            'image' => ['nullable', 'image', 'max:15360'],
        ];

        if ($this->hasProductColumn('show_in_pos')) {
            $rules['show_in_pos'] = ['nullable', 'boolean'];
        }

        if ($this->hasProductColumn('is_custom_order')) {
            $rules['is_custom_order'] = ['nullable', 'boolean'];
        }

        if ($this->hasProductColumn('is_temporary_order_item')) {
            $rules['is_temporary_order_item'] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);

        $category = $this->getCategory((int) $data['category_id']);

        if (!$category) {
            return response()->json([
                'message' => 'Categoría inválida.',
                'errors' => [
                    'category_id' => ['Categoría inválida.']
                ]
            ], 422);
        }

        $this->normalizeOpticalFields($data, (int) $data['category_id']);
        $treatmentIds = $this->normalizeTreatments($data, (int) $data['category_id']);

        return DB::transaction(function () use ($data, $request, $treatmentIds, $category) {
            $p = new Product();

            $p->sku = $data['sku'];
            $p->name = $data['name'];
            $p->description = $data['description'] ?? null;

            $p->category_id = (int) $data['category_id'];

            $p->type = $data['type'] ?? null;
            $p->material = $data['material'] ?? null;

            if ($this->isMicasCategory((int) $category->id)) {
                $p->buy_price = $this->hasCategoryColumn('buy_price')
                    ? (float) ($category->buy_price ?? 0)
                    : (float) ($data['buyPrice'] ?? 0);

                $p->sale_price = $this->hasCategoryColumn('sale_price')
                    ? (float) ($category->sale_price ?? 0)
                    : (float) ($data['salePrice'] ?? 0);
            } else {
                $p->buy_price = (float) ($data['buyPrice'] ?? 0);
                $p->sale_price = (float) ($data['salePrice'] ?? 0);
            }

            $p->min_stock = (int) ($data['minStock'] ?? 0);
            $p->max_stock = isset($data['maxStock']) ? (int) $data['maxStock'] : null;

            $p->supplier_id = $data['supplier_id'] ?? null;
            $p->box_id = $data['box_id'] ?? null;
            $p->lens_type_id = $data['lens_type_id'] ?? null;
            $p->material_id = $data['material_id'] ?? null;

            $p->sphere = array_key_exists('sphere', $data) ? $data['sphere'] : null;
            $p->cylinder = array_key_exists('cylinder', $data) ? $data['cylinder'] : null;
            $p->axis = array_key_exists('axis', $data) ? $data['axis'] : null;

            if ($this->isMicasCategory((int) $category->id)) {
                $p->axis = null;
            }

            $p->active = 1;

            if ($this->hasProductColumn('show_in_pos')) {
                $p->show_in_pos = array_key_exists('show_in_pos', $data)
                    ? (int) ((bool) $data['show_in_pos'])
                    : 1;
            }

            if ($this->hasProductColumn('is_custom_order')) {
                $p->is_custom_order = array_key_exists('is_custom_order', $data)
                    ? (int) ((bool) $data['is_custom_order'])
                    : 0;
            }

            if ($this->hasProductColumn('is_temporary_order_item')) {
                $p->is_temporary_order_item = array_key_exists('is_temporary_order_item', $data)
                    ? (int) ((bool) $data['is_temporary_order_item'])
                    : 0;
            }

            $this->fillImage($p, $request);
            $p->save();

            DB::table('inventory')->insert([
                'product_id' => $p->id,
                'stock' => 0,
                'reserved' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncTreatments((int) $p->id, $treatmentIds);

            return response()->json([
                'ok' => true,
                'product' => $this->productResponse($p),
            ], 201);
        });
    }

    public function update(Request $request, $id)
    {
        $p = Product::whereNull('deleted_at')->findOrFail($id);

        $rules = [
            'sku' => ['sometimes', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($p->id)],
            'name' => ['sometimes', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'type' => ['nullable', 'string', 'max:80'],
            'material' => ['nullable', 'string', 'max:80'],
            'buyPrice' => ['nullable', 'numeric', 'min:0'],
            'salePrice' => ['nullable', 'numeric', 'min:0'],
            'minStock' => ['nullable', 'integer', 'min:0'],
            'maxStock' => ['nullable', 'integer', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            'lens_type_id' => ['nullable', 'integer', 'exists:lens_types,id'],
            'material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'sphere' => ['nullable', 'numeric', 'between:-40,40'],
            'cylinder' => ['nullable', 'numeric', 'between:-40,0'],
            'axis' => ['nullable', 'integer', 'between:1,180'],

            'treatments' => ['nullable', 'array'],
            'treatments.*' => ['integer', 'exists:treatments,id'],

            'image' => ['nullable', 'image', 'max:15360'],
        ];

        if ($this->hasProductColumn('show_in_pos')) {
            $rules['show_in_pos'] = ['nullable', 'boolean'];
        }

        if ($this->hasProductColumn('is_custom_order')) {
            $rules['is_custom_order'] = ['nullable', 'boolean'];
        }

        if ($this->hasProductColumn('is_temporary_order_item')) {
            $rules['is_temporary_order_item'] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);

        $effectiveCategoryId = array_key_exists('category_id', $data)
            ? (int) $data['category_id']
            : (int) $p->category_id;

        $category = $this->getCategory($effectiveCategoryId);

        if (!$category) {
            return response()->json([
                'message' => 'Categoría inválida.',
                'errors' => [
                    'category_id' => ['Categoría inválida.']
                ]
            ], 422);
        }

        $this->normalizeOpticalFields($data, $effectiveCategoryId, $p);
        $treatmentIds = $this->normalizeTreatments($data, $effectiveCategoryId);

        if (array_key_exists('sku', $data)) $p->sku = $data['sku'];
        if (array_key_exists('name', $data)) $p->name = $data['name'];
        if (array_key_exists('description', $data)) $p->description = $data['description'];
        if (array_key_exists('category_id', $data)) $p->category_id = (int) $data['category_id'];
        if (array_key_exists('type', $data)) $p->type = $data['type'];
        if (array_key_exists('material', $data)) $p->material = $data['material'];

        if ($this->isMicasCategory($effectiveCategoryId)) {
            $p->buy_price = $this->hasCategoryColumn('buy_price')
                ? (float) ($category->buy_price ?? 0)
                : (array_key_exists('buyPrice', $data) ? (float) $data['buyPrice'] : (float) $p->buy_price);

            $p->sale_price = $this->hasCategoryColumn('sale_price')
                ? (float) ($category->sale_price ?? 0)
                : (array_key_exists('salePrice', $data) ? (float) $data['salePrice'] : (float) $p->sale_price);
        } else {
            if (array_key_exists('buyPrice', $data)) $p->buy_price = (float) ($data['buyPrice'] ?? 0);
            if (array_key_exists('salePrice', $data)) $p->sale_price = (float) ($data['salePrice'] ?? 0);
        }

        if (array_key_exists('minStock', $data)) $p->min_stock = (int) ($data['minStock'] ?? 0);
        if (array_key_exists('maxStock', $data)) $p->max_stock = isset($data['maxStock']) ? (int) $data['maxStock'] : null;
        if (array_key_exists('supplier_id', $data)) $p->supplier_id = $data['supplier_id'] ? (int) $data['supplier_id'] : null;
        if (array_key_exists('box_id', $data)) $p->box_id = $data['box_id'] ? (int) $data['box_id'] : null;
        if (array_key_exists('lens_type_id', $data)) $p->lens_type_id = $data['lens_type_id'] ? (int) $data['lens_type_id'] : null;
        if (array_key_exists('material_id', $data)) $p->material_id = $data['material_id'] ? (int) $data['material_id'] : null;
        if (array_key_exists('sphere', $data)) $p->sphere = isset($data['sphere']) ? (float) $data['sphere'] : null;
        if (array_key_exists('cylinder', $data)) $p->cylinder = isset($data['cylinder']) ? (float) $data['cylinder'] : null;
        if (array_key_exists('axis', $data)) $p->axis = isset($data['axis']) ? (int) $data['axis'] : null;

        if ($this->isMicasCategory($effectiveCategoryId)) {
            $p->axis = null;
        }

        if ($this->hasProductColumn('show_in_pos') && array_key_exists('show_in_pos', $data)) {
            $p->show_in_pos = (int) ((bool) $data['show_in_pos']);
        }

        if ($this->hasProductColumn('is_custom_order') && array_key_exists('is_custom_order', $data)) {
            $p->is_custom_order = (int) ((bool) $data['is_custom_order']);
        }

        if ($this->hasProductColumn('is_temporary_order_item') && array_key_exists('is_temporary_order_item', $data)) {
            $p->is_temporary_order_item = (int) ((bool) $data['is_temporary_order_item']);
        }

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

        if ($this->isMicasCategory((int) $p->category_id)) {
            $this->syncTreatments((int) $p->id, $treatmentIds);
        } else {
            $this->syncTreatments((int) $p->id, []);
        }

        return response()->json([
            'ok' => true,
            'product' => $this->productResponse($p),
        ], 200);
    }

    public function image($id)
{
    $productColumns = ['id', 'category_id'];

    foreach (['image_blob', 'image_path', 'image_mime', 'image_filename'] as $col) {
        if ($this->hasProductColumn($col)) {
            $productColumns[] = $col;
        }
    }

    $p = Product::query()
        ->select($productColumns)
        ->where('id', $id)
        ->whereNull('deleted_at')
        ->first();

    if (!$p) {
        return response()->noContent();
    }

    /*
    |--------------------------------------------------------------------------
    | 1) Imagen propia del producto en BLOB
    |--------------------------------------------------------------------------
    */
    if (
        $this->hasProductColumn('image_blob') &&
        array_key_exists('image_blob', $p->getAttributes()) &&
        !empty($p->image_blob)
    ) {
        $mime = $p->image_mime ?: 'image/jpeg';
        $filename = $p->image_filename ?: "product_{$p->id}.jpg";

        return response($p->image_blob, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2) Imagen propia del producto en storage/path
    |--------------------------------------------------------------------------
    */
    if (
        $this->hasProductColumn('image_path') &&
        !empty($p->image_path) &&
        Storage::disk('local')->exists($p->image_path)
    ) {
        $mime = $p->image_mime ?: Storage::disk('local')->mimeType($p->image_path) ?: 'image/jpeg';
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

    /*
    |--------------------------------------------------------------------------
    | 3) Fallback: imagen heredada de la categoría
    |--------------------------------------------------------------------------
    */
    if (!empty($p->category_id)) {
        $categoryColumns = ['id'];

        foreach (['image_blob', 'image_path', 'image_mime', 'image_filename'] as $col) {
            if ($this->hasCategoryColumn($col)) {
                $categoryColumns[] = $col;
            }
        }

        $category = Category::query()
            ->select($categoryColumns)
            ->where('id', $p->category_id)
            ->whereNull('deleted_at')
            ->first();

        if ($category) {
            /*
            |--------------------------------------------------------------------------
            | 3.1) Imagen de categoría en BLOB
            |--------------------------------------------------------------------------
            */
            if (
                $this->hasCategoryColumn('image_blob') &&
                array_key_exists('image_blob', $category->getAttributes()) &&
                !empty($category->image_blob)
            ) {
                $mime = $category->image_mime ?: 'image/jpeg';
                $filename = $category->image_filename ?: "category_{$category->id}.jpg";

                return response($category->image_blob, 200, [
                    'Content-Type' => $mime,
                    'Content-Disposition' => 'inline; filename="' . $filename . '"',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 3.2) Imagen de categoría en storage/path
            |--------------------------------------------------------------------------
            */
            if (
                $this->hasCategoryColumn('image_path') &&
                !empty($category->image_path) &&
                Storage::disk('local')->exists($category->image_path)
            ) {
                $mime = $category->image_mime ?: Storage::disk('local')->mimeType($category->image_path) ?: 'image/jpeg';
                $filename = $category->image_filename ?: "category_{$category->id}.jpg";
                $stream = Storage::disk('local')->readStream($category->image_path);

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
        }
    }

    return response()->noContent();
}

    public function destroy($id)
    {
        $p = Product::whereNull('deleted_at')->findOrFail($id);

        if (!empty($p->image_path) && Storage::disk('local')->exists($p->image_path)) {
            Storage::disk('local')->delete($p->image_path);
        }

        DB::table('product_treatments')
            ->where('product_id', $p->id)
            ->delete();

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

        $category = $p->category;

        $hasOwnImage =
            !empty($p->image_path) ||
            (
                $this->hasProductColumn('image_blob') &&
                array_key_exists('image_blob', $p->getAttributes()) &&
                !empty($p->image_blob)
            );

        $hasCategoryImage = false;

        if ($category) {
            $hasCategoryImage =
                (
                    $this->hasCategoryColumn('image_blob') &&
                    array_key_exists('image_blob', $category->getAttributes()) &&
                    !empty($category->image_blob)
                ) ||
                (
                    $this->hasCategoryColumn('image_path') &&
                    !empty($category->image_path)
                );
        }

        $hasImage = $hasOwnImage || $hasCategoryImage;

        return response()->json([
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'description' => $p->description,

            'category_id' => $p->category_id,
            'category' => $category?->code ?? null,
            'category_label' => $category?->name ?? null,
            'category_code' => $category?->code ?? null,
            'category_name' => $category?->name ?? null,
            'category_is_mica' => $category ? $this->isMicasCategory((int) $category->id) : false,

            'type' => $p->type,
            'material' => $p->material,

            'buy_price' => $p->buy_price,
            'sale_price' => $p->sale_price,
            'buyPrice' => (float) $p->buy_price,
            'salePrice' => (float) $p->sale_price,

            'min_stock' => $p->min_stock,
            'max_stock' => $p->max_stock,
            'minStock' => (int) ($p->min_stock ?? 0),
            'maxStock' => $p->max_stock !== null ? (int) $p->max_stock : null,

            'supplier_id' => $p->supplier_id,
            'box_id' => $p->box_id,
            'lens_type_id' => $p->lens_type_id,
            'material_id' => $p->material_id,

            'sphere' => $p->sphere !== null ? (float) $p->sphere : null,
            'cylinder' => $p->cylinder !== null ? (float) $p->cylinder : null,
            'axis' => $p->axis !== null ? (int) $p->axis : null,

            'treatments' => $this->getTreatmentsForProduct((int) $p->id),

            'imageUrl' => $hasImage ? url("/api/products/{$p->id}/image") : null,
            'image_url' => $hasImage ? url("/api/products/{$p->id}/image") : null,
            'has_image' => $hasImage,
            'has_own_image' => $hasOwnImage,
            'has_category_image' => $hasCategoryImage,

            'active' => (bool) $p->active,

            'show_in_pos' => $this->hasProductColumn('show_in_pos')
                ? (bool) ($p->show_in_pos ?? true)
                : true,

            'is_custom_order' => $this->hasProductColumn('is_custom_order')
                ? (bool) ($p->is_custom_order ?? false)
                : false,

            'is_temporary_order_item' => $this->hasProductColumn('is_temporary_order_item')
                ? (bool) ($p->is_temporary_order_item ?? false)
                : false,
        ]);
    }
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $ids = collect($data['ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return response()->json([
                'ok' => false,
                'message' => 'No se recibieron productos válidos.',
            ], 422);
        }

        $deleted = [];
        $skipped = [];

        DB::transaction(function () use ($ids, &$deleted, &$skipped) {
            $products = Product::query()
                ->whereIn('id', $ids)
                ->whereNull('deleted_at')
                ->get();

            foreach ($ids as $id) {
                $p = $products->firstWhere('id', $id);

                if (!$p) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => null,
                        'reason' => 'No existe o ya fue eliminado.',
                    ];

                    continue;
                }

                $inventory = DB::table('inventory')
                    ->where('product_id', $p->id)
                    ->first();

                $reserved = (int) ($inventory->reserved ?? 0);

                if ($reserved > 0) {
                    $skipped[] = [
                        'id' => (int) $p->id,
                        'name' => $p->name,
                        'reason' => 'Tiene stock reservado.',
                    ];

                    continue;
                }

                DB::table('products')
                    ->where('id', $p->id)
                    ->update([
                        'active' => 0,
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);

                $deleted[] = [
                    'id' => (int) $p->id,
                    'name' => $p->name,
                ];
            }
        });

        return response()->json([
            'ok' => true,
            'message' => 'Borrado masivo completado.',
            'deleted_count' => count($deleted),
            'skipped_count' => count($skipped),
            'deleted' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function thumb($id)
    {
        $thumbPath = "products/thumbs/product_{$id}.jpg";

        if (Storage::disk('local')->exists($thumbPath)) {
            $stream = Storage::disk('local')->readStream($thumbPath);

            return response()->stream(function () use ($stream) {
                fpassthru($stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Disposition' => 'inline; filename="product_' . $id . '_thumb.jpg"',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validar que GD esté activo
        |--------------------------------------------------------------------------
        */
        if (!function_exists('imagecreatefromstring')) {
            return response()->json([
                'ok' => false,
                'message' => 'La extensión GD de PHP no está activa. Activa extension=gd en php.ini y reinicia Laravel.',
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Producto
        |--------------------------------------------------------------------------
        */
        $productColumns = ['id', 'category_id'];

        foreach (['image_blob', 'image_path', 'image_mime', 'image_filename'] as $col) {
            if ($this->hasProductColumn($col)) {
                $productColumns[] = $col;
            }
        }

        $p = Product::query()
            ->select($productColumns)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$p) {
            return response()->noContent();
        }

        $sourceBinary = null;

        /*
        |--------------------------------------------------------------------------
        | 1) Imagen propia del producto en BLOB, si existe la columna
        |--------------------------------------------------------------------------
        */
        if (
            $this->hasProductColumn('image_blob') &&
            array_key_exists('image_blob', $p->getAttributes()) &&
            !empty($p->image_blob)
        ) {
            $sourceBinary = $p->image_blob;
        }

        /*
        |--------------------------------------------------------------------------
        | 2) Imagen propia del producto en storage/path
        |--------------------------------------------------------------------------
        */
        if (
            !$sourceBinary &&
            $this->hasProductColumn('image_path') &&
            !empty($p->image_path) &&
            Storage::disk('local')->exists($p->image_path)
        ) {
            $sourceBinary = Storage::disk('local')->get($p->image_path);
        }

        /*
        |--------------------------------------------------------------------------
        | 3) Fallback: imagen heredada de la categoría
        |--------------------------------------------------------------------------
        */
        if (!$sourceBinary && !empty($p->category_id)) {
            $categoryColumns = ['id'];

            foreach (['image_blob', 'image_path', 'image_mime', 'image_filename'] as $col) {
                if ($this->hasCategoryColumn($col)) {
                    $categoryColumns[] = $col;
                }
            }

            $category = Category::query()
                ->select($categoryColumns)
                ->where('id', $p->category_id)
                ->whereNull('deleted_at')
                ->first();

            if ($category) {
                if (
                    $this->hasCategoryColumn('image_blob') &&
                    array_key_exists('image_blob', $category->getAttributes()) &&
                    !empty($category->image_blob)
                ) {
                    $sourceBinary = $category->image_blob;
                }

                if (
                    !$sourceBinary &&
                    $this->hasCategoryColumn('image_path') &&
                    !empty($category->image_path) &&
                    Storage::disk('local')->exists($category->image_path)
                ) {
                    $sourceBinary = Storage::disk('local')->get($category->image_path);
                }
            }
        }

        if (!$sourceBinary) {
            return response()->noContent();
        }

        /*
        |--------------------------------------------------------------------------
        | 4) Crear miniatura con GD
        |--------------------------------------------------------------------------
        */
        $sourceImage = @\imagecreatefromstring($sourceBinary);

        if (!$sourceImage) {
            return response()->noContent();
        }

        $srcWidth = \imagesx($sourceImage);
        $srcHeight = \imagesy($sourceImage);

        if ($srcWidth <= 0 || $srcHeight <= 0) {
            \imagedestroy($sourceImage);
            return response()->noContent();
        }

        $targetWidth = 360;
        $targetHeight = 220;

        $srcRatio = $srcWidth / $srcHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($srcRatio > $targetRatio) {
            $newWidth = $targetWidth;
            $newHeight = (int) round($targetWidth / $srcRatio);
        } else {
            $newHeight = $targetHeight;
            $newWidth = (int) round($targetHeight * $srcRatio);
        }

        $thumb = \imagecreatetruecolor($targetWidth, $targetHeight);

        if (!$thumb) {
            \imagedestroy($sourceImage);
            return response()->noContent();
        }

        $white = \imagecolorallocate($thumb, 255, 255, 255);
        \imagefill($thumb, 0, 0, $white);

        $dstX = (int) floor(($targetWidth - $newWidth) / 2);
        $dstY = (int) floor(($targetHeight - $newHeight) / 2);

        \imagecopyresampled(
            $thumb,
            $sourceImage,
            $dstX,
            $dstY,
            0,
            0,
            $newWidth,
            $newHeight,
            $srcWidth,
            $srcHeight
        );

        ob_start();
        \imagejpeg($thumb, null, 78);
        $thumbBinary = ob_get_clean();

        \imagedestroy($sourceImage);
        \imagedestroy($thumb);

        if (!$thumbBinary) {
            return response()->noContent();
        }

        Storage::disk('local')->put($thumbPath, $thumbBinary);

        return response($thumbBinary, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="product_' . $id . '_thumb.jpg"',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}