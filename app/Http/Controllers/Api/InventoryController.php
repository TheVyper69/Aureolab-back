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

    private function visibleInventoryQuery()
    {
        $query = Product::with([
            'category:id,code,name',
            'inventory:id,product_id,stock,reserved',
            'variants.inventory'
        ])
        ->where('active', 1)
        ->whereNull('deleted_at');

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
        $products = $this->visibleInventoryQuery()->get();

        $data = $products->map(function ($p) {
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

                'imageUrl'       => !empty($p->image_path) || !empty($p->image_filename)
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
                'variants'  => $p->variants->map(function ($v) {
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
                })->values(),
            ];
        })->values();

        return response()->json($data);
    }
}