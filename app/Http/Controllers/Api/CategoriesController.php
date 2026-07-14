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

class CategoriesController extends Controller
{
    private function hasCategoryColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::hasColumn('categories', $column);
        }

        return $cache[$column];
    }

    private function hasProductColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::hasColumn('products', $column);
        }

        return $cache[$column];
    }

    private function categoryResponse(Category $cat, array $extra = []): array
    {
        $response = [
            'id' => (int) $cat->id,
            'code' => $cat->code,
            'name' => $cat->name,
            'description' => $cat->description,
        ];

        if ($this->hasCategoryColumn('is_mica')) {
            $response['is_mica'] = (bool) ($cat->is_mica ?? false);
            $response['isMica'] = (bool) ($cat->is_mica ?? false);
        } else {
            $response['is_mica'] = false;
            $response['isMica'] = false;
        }

        if ($this->hasCategoryColumn('buy_price')) {
            $response['buy_price'] = (float) ($cat->buy_price ?? 0);
            $response['buyPrice'] = (float) ($cat->buy_price ?? 0);
        } else {
            $response['buy_price'] = 0;
            $response['buyPrice'] = 0;
        }

        if ($this->hasCategoryColumn('sale_price')) {
            $response['sale_price'] = (float) ($cat->sale_price ?? 0);
            $response['salePrice'] = (float) ($cat->sale_price ?? 0);
        } else {
            $response['sale_price'] = 0;
            $response['salePrice'] = 0;
        }

        if ($this->hasCategoryColumn('last_price_update_at')) {
            $response['last_price_update_at'] = $cat->last_price_update_at;
        } else {
            $response['last_price_update_at'] = null;
        }

        if ($this->hasCategoryColumn('image_path')) {
            $response['image_path'] = $cat->image_path;
            $response['image_filename'] = $this->hasCategoryColumn('image_filename')
                ? $cat->image_filename
                : null;
            $response['image_mime'] = $this->hasCategoryColumn('image_mime')
                ? $cat->image_mime
                : null;

            $response['imageUrl'] = !empty($cat->image_path)
                ? url("/api/categories/{$cat->id}/image")
                : null;

            $response['image_url'] = $response['imageUrl'];
            $response['has_image'] = !empty($cat->image_path);
        } else {
            $response['image_path'] = null;
            $response['image_filename'] = null;
            $response['image_mime'] = null;
            $response['imageUrl'] = null;
            $response['image_url'] = null;
            $response['has_image'] = false;
        }

        return array_merge($response, $extra);
    }

    private function categorySelectColumns(): array
    {
        $columns = [
            'id',
            'code',
            'name',
            'description',
        ];

        if ($this->hasCategoryColumn('is_mica')) {
            $columns[] = 'is_mica';
        }

        if ($this->hasCategoryColumn('buy_price')) {
            $columns[] = 'buy_price';
        }

        if ($this->hasCategoryColumn('sale_price')) {
            $columns[] = 'sale_price';
        }

        if ($this->hasCategoryColumn('last_price_update_at')) {
            $columns[] = 'last_price_update_at';
        }

        if ($this->hasCategoryColumn('image_filename')) {
            $columns[] = 'image_filename';
        }

        if ($this->hasCategoryColumn('image_mime')) {
            $columns[] = 'image_mime';
        }

        if ($this->hasCategoryColumn('image_path')) {
            $columns[] = 'image_path';
        }

        return $columns;
    }

    private function baseRules(?Category $cat = null): array
    {
        $catId = $cat?->id;

        $rules = [
            'code' => [
                'required',
                'string',
                'max:40',
                $catId
                    ? "unique:categories,code,{$catId}"
                    : 'unique:categories,code',
            ],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
        ];

        if ($this->hasCategoryColumn('is_mica')) {
            $rules['is_mica'] = ['nullable', 'boolean'];
            $rules['isMica'] = ['nullable', 'boolean'];
        }

        if ($this->hasCategoryColumn('buy_price')) {
            $rules['buy_price'] = ['nullable', 'numeric', 'min:0'];
            $rules['buyPrice'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($this->hasCategoryColumn('sale_price')) {
            $rules['sale_price'] = ['nullable', 'numeric', 'min:0'];
            $rules['salePrice'] = ['nullable', 'numeric', 'min:0'];
        }

        if ($this->hasCategoryColumn('image_path')) {
            $rules['image'] = ['nullable', 'image', 'max:15360'];
        }

        return $rules;
    }

    private function priceFromRequest(array $data, string $snakeKey, string $camelKey, float $default = 0): float
    {
        if (array_key_exists($snakeKey, $data) && $data[$snakeKey] !== null && $data[$snakeKey] !== '') {
            return (float) $data[$snakeKey];
        }

        if (array_key_exists($camelKey, $data) && $data[$camelKey] !== null && $data[$camelKey] !== '') {
            return (float) $data[$camelKey];
        }

        return $default;
    }

    private function boolFromRequest(array $data, string $snakeKey, string $camelKey, bool $default = false): bool
    {
        if (array_key_exists($snakeKey, $data)) {
            return (bool) $data[$snakeKey];
        }

        if (array_key_exists($camelKey, $data)) {
            return (bool) $data[$camelKey];
        }

        return $default;
    }

    private function applyDataToCategory(Category $cat, array $data): void
    {
        $cat->code = $data['code'];
        $cat->name = $data['name'];
        $cat->description = $data['description'] ?? null;

        if ($this->hasCategoryColumn('is_mica')) {
            $cat->is_mica = (int) $this->boolFromRequest(
                $data,
                'is_mica',
                'isMica',
                (bool) ($cat->is_mica ?? false)
            );
        }

        if ($this->hasCategoryColumn('buy_price')) {
            $cat->buy_price = $this->priceFromRequest(
                $data,
                'buy_price',
                'buyPrice',
                (float) ($cat->buy_price ?? 0)
            );
        }

        if ($this->hasCategoryColumn('sale_price')) {
            $cat->sale_price = $this->priceFromRequest(
                $data,
                'sale_price',
                'salePrice',
                (float) ($cat->sale_price ?? 0)
            );
        }
    }

    private function fillCategoryImage(Category $cat, Request $request): void
    {
        if (!$this->hasCategoryColumn('image_path')) {
            return;
        }

        if (!$request->hasFile('image')) {
            return;
        }

        $file = $request->file('image');

        if (!$file || !$file->isValid()) {
            return;
        }

        if (!empty($cat->image_path) && Storage::disk('local')->exists($cat->image_path)) {
            Storage::disk('local')->delete($cat->image_path);
        }

        $ext = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $filename = 'categories/' . Str::uuid() . '.' . strtolower($ext);

        Storage::disk('local')->putFileAs(
            'categories',
            $file,
            basename($filename)
        );

        $cat->image_path = $filename;

        if ($this->hasCategoryColumn('image_filename')) {
            $cat->image_filename = $file->getClientOriginalName();
        }

        if ($this->hasCategoryColumn('image_mime')) {
            $cat->image_mime = $file->getMimeType();
        }
    }

    private function shouldUpdateProductsPrices(Request $request): bool
    {
        return $request->boolean('update_products_prices')
            || $request->boolean('updateProductsPrices')
            || $request->boolean('apply_prices_to_products')
            || $request->boolean('applyPricesToProducts');
    }

    private function shouldRemoveImage(Request $request): bool
    {
        return $request->boolean('remove_image')
            || $request->boolean('removeImage')
            || $request->boolean('delete_image')
            || $request->boolean('deleteImage');
    }

    private function removeCategoryImage(Category $cat): void
    {
        if (!$this->hasCategoryColumn('image_path')) {
            return;
        }

        if (!empty($cat->image_path) && Storage::disk('local')->exists($cat->image_path)) {
            Storage::disk('local')->delete($cat->image_path);
        }

        $cat->image_path = null;

        if ($this->hasCategoryColumn('image_filename')) {
            $cat->image_filename = null;
        }

        if ($this->hasCategoryColumn('image_mime')) {
            $cat->image_mime = null;
        }
    }

    private function updateProductsPricesFromCategory(Category $cat): int
    {
        if (
            !$this->hasProductColumn('category_id') ||
            !$this->hasProductColumn('buy_price') ||
            !$this->hasProductColumn('sale_price')
        ) {
            return 0;
        }

        $query = Product::query()
            ->where('category_id', $cat->id);

        if ($this->hasProductColumn('deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($this->hasProductColumn('active')) {
            $query->where('active', 1);
        }

        $payload = [
            'buy_price' => (float) ($cat->buy_price ?? 0),
            'sale_price' => (float) ($cat->sale_price ?? 0),
            'updated_at' => now(),
        ];

        return $query->update($payload);
    }

    public function index()
    {
        $cats = Category::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get($this->categorySelectColumns());

        return response()->json(
            $cats->map(fn ($cat) => $this->categoryResponse($cat))->values()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->baseRules());

        return DB::transaction(function () use ($request, $data) {
            $cat = new Category();

            $this->applyDataToCategory($cat, $data);

            if ($this->hasCategoryColumn('last_price_update_at')) {
                $cat->last_price_update_at = null;
            }

            $this->fillCategoryImage($cat, $request);

            $cat->save();

            return response()->json($this->categoryResponse($cat), 201);
        });
    }

    public function update(Request $request, $id)
    {
        $cat = Category::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $oldBuyPrice = $this->hasCategoryColumn('buy_price')
            ? (float) ($cat->buy_price ?? 0)
            : 0;

        $oldSalePrice = $this->hasCategoryColumn('sale_price')
            ? (float) ($cat->sale_price ?? 0)
            : 0;

        $data = $request->validate(array_merge(
            $this->baseRules($cat),
            [
                'update_products_prices' => ['nullable', 'boolean'],
                'updateProductsPrices' => ['nullable', 'boolean'],
                'apply_prices_to_products' => ['nullable', 'boolean'],
                'applyPricesToProducts' => ['nullable', 'boolean'],

                'remove_image' => ['nullable', 'boolean'],
                'removeImage' => ['nullable', 'boolean'],
                'delete_image' => ['nullable', 'boolean'],
                'deleteImage' => ['nullable', 'boolean'],
            ]
        ));

        return DB::transaction(function () use ($request, $cat, $data, $oldBuyPrice, $oldSalePrice) {
            $this->applyDataToCategory($cat, $data);

            if ($this->shouldRemoveImage($request)) {
                $this->removeCategoryImage($cat);
            }

            $this->fillCategoryImage($cat, $request);

            $newBuyPrice = $this->hasCategoryColumn('buy_price')
                ? (float) ($cat->buy_price ?? 0)
                : 0;

            $newSalePrice = $this->hasCategoryColumn('sale_price')
                ? (float) ($cat->sale_price ?? 0)
                : 0;

            $pricesChanged =
                abs($newBuyPrice - $oldBuyPrice) > 0.00001 ||
                abs($newSalePrice - $oldSalePrice) > 0.00001;

            $updatedProducts = 0;

            if ($pricesChanged && $this->shouldUpdateProductsPrices($request)) {
                $updatedProducts = $this->updateProductsPricesFromCategory($cat);

                if ($this->hasCategoryColumn('last_price_update_at')) {
                    $cat->last_price_update_at = now();
                }
            }

            $cat->save();

            return response()->json($this->categoryResponse($cat, [
                'ok' => true,
                'prices_changed' => $pricesChanged,
                'updated_products' => $updatedProducts,
            ]));
        });
    }

    public function image($id)
    {
        $cat = Category::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$cat || !$this->hasCategoryColumn('image_path') || empty($cat->image_path)) {
            return response()->noContent();
        }

        if (!Storage::disk('local')->exists($cat->image_path)) {
            return response()->noContent();
        }

        $mime = $this->hasCategoryColumn('image_mime')
            ? ($cat->image_mime ?: 'image/jpeg')
            : 'image/jpeg';

        $filename = $this->hasCategoryColumn('image_filename')
            ? ($cat->image_filename ?: "category_{$cat->id}.jpg")
            : "category_{$cat->id}.jpg";

        $stream = Storage::disk('local')->readStream($cat->image_path);

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
        $cat = Category::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $productsCount = 0;

        if ($this->hasProductColumn('category_id')) {
            $productsQuery = Product::query()
                ->where('category_id', $cat->id);

            if ($this->hasProductColumn('deleted_at')) {
                $productsQuery->whereNull('deleted_at');
            }

            $productsCount = $productsQuery->count();
        }

        if ($productsCount > 0) {
            return response()->json([
                'ok' => false,
                'message' => 'No puedes eliminar esta categoría porque tiene productos relacionados.',
                'products_count' => $productsCount,
            ], 422);
        }

        $this->removeCategoryImage($cat);

        $cat->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Categoría eliminada correctamente.',
        ]);
    }
}