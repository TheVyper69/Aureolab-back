<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InventoryController extends Controller
{
    private function hasProductColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::hasColumn('products', $column);
        }

        return $cache[$column];
    }

    private function productSelectColumns(): array
    {
        $wanted = [
            'id',
            'sku',
            'name',
            'description',
            'category_id',
            'type',
            'material',
            'sale_price',
            'buy_price',
            'min_stock',
            'max_stock',
            'supplier_id',
            'box_id',
            'lens_type_id',
            'material_id',
            'sphere',
            'cylinder',
            'axis',
            'image_path',
            'image_filename',
            'show_in_pos',
            'is_custom_order',
            'is_temporary_order_item',
            'active',
            'deleted_at',
        ];

        return array_values(array_filter(
            $wanted,
            fn ($column) => $this->hasProductColumn($column)
        ));
    }

    private function visibleInventoryQuery(bool $includeVariants = false)
    {
        $relations = [
            'category:id,code,name',
            'inventory:id,product_id,stock,reserved',
        ];

        if ($includeVariants) {
            $relations[] = 'variants.inventory';
        }

        $query = Product::query()
            ->select($this->productSelectColumns())
            ->with($relations)
            ->where('active', 1)
            ->whereNull('deleted_at');

        /*
         * Importante:
         * No seleccionamos image_blob completo en el listado de inventario.
         * Solo calculamos si existe para poder construir imageUrl sin mandar
         * binarios pesados en /inventory.
         */
        if ($this->hasProductColumn('image_blob')) {
            $query->selectRaw('image_blob IS NOT NULL as has_image_blob');
        }

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

    public function index(Request $request)
    {
        $includeVariants = $request->boolean('include_variants')
            || $request->boolean('includeVariants')
            || $request->boolean('variants');

        $products = $this->visibleInventoryQuery($includeVariants)->get();

        $data = $products->map(function ($p) use ($includeVariants) {
            $stock = (int) ($p->inventory->stock ?? 0);
            $reserved = (int) ($p->inventory->reserved ?? 0);

            $product = [
                'id'             => $p->id,
                'sku'            => $p->sku,
                'name'           => $p->name,
                'description'    => $p->description,

                'category'       => $p->category?->code ?? $p->category?->name,
                'category_label' => $p->category?->name,
                'category_id'    => $p->category_id,

                'type'           => $p->type,
                'material'       => $p->material,

                'salePrice'      => (float) $p->sale_price,
                'buyPrice'       => (float) $p->buy_price,
                'minStock'       => (int) $p->min_stock,
                'maxStock'       => $p->max_stock !== null ? (int) $p->max_stock : null,

                'supplier_id'    => $p->supplier_id,
                'box_id'         => $p->box_id,
                'lens_type_id'   => $p->lens_type_id,
                'material_id'    => $p->material_id,
                'sphere'         => $p->sphere,
                'cylinder'       => $p->cylinder,
                'axis'           => $p->axis,

                'imageUrl'       => (
                    !empty($p->image_path) ||
                    !empty($p->image_filename) ||
                    (isset($p->has_image_blob) && (bool) $p->has_image_blob)
                )
                    ? url("/api/products/{$p->id}/image")
                    : null,
            ];

            if ($this->hasProductColumn('show_in_pos')) {
                $product['show_in_pos'] = (bool) ($p->show_in_pos ?? true);
            }

            if ($this->hasProductColumn('is_custom_order')) {
                $product['is_custom_order'] = (bool) ($p->is_custom_order ?? false);
            }

            if ($this->hasProductColumn('is_temporary_order_item')) {
                $product['is_temporary_order_item'] = (bool) ($p->is_temporary_order_item ?? false);
            }

            return [
                'stock'     => $stock,
                'reserved'  => $reserved,
                'available' => max(0, $stock - $reserved),
                'critical'  => $stock <= (int) ($p->min_stock ?? 0),
                'product'   => $product,
                'variants'  => $includeVariants
                    ? $p->variants->map(function ($v) {
                        return [
                            'id'    => $v->id,
                            'type'  => $v->variant_type,
                            'sph'   => $v->sph,
                            'cyl'   => $v->cyl,
                            'add'   => $v->add_power,
                            'bc'    => $v->bc,
                            'dia'   => $v->dia,
                            'color' => $v->color,
                            'stock' => (int) ($v->inventory->stock ?? 0),
                        ];
                    })->values()
                    : [],
            ];
        })->values();

        return response()->json($data);
    }
}