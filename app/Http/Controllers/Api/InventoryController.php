<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
{
    $products = Product::with([
        'category:id,code,name',
        'inventory:id,product_id,stock',
        'variants.inventory'
    ])
    ->where('active', 1)
    ->whereNull('deleted_at')
    ->get();

    $data = $products->map(function ($p) {
        $stock = $p->inventory->stock ?? 0;

        return [
            'stock' => (int)$stock,
            'critical' => (int)$stock <= (int)($p->min_stock ?? 0),
            'product' => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'description' => $p->description,
                'category' => $p->category?->code ?? $p->category?->name,
                'category_label' => $p->category?->name,
                'salePrice' => (float)$p->sale_price,
                'buyPrice' => (float)$p->buy_price,
                'minStock' => (int)$p->min_stock,
                'maxStock' => $p->max_stock !== null ? (int)$p->max_stock : null,
                'imageUrl' => $p->image_filename ? url("/api/products/{$p->id}/image") : null,
            ],
            'variants' => $p->variants->map(function ($v) {
                return [
                    'id' => $v->id,
                    'type' => $v->variant_type,
                    'sph' => $v->sph,
                    'cyl' => $v->cyl,
                    'add' => $v->add_power,
                    'bc' => $v->bc,
                    'dia' => $v->dia,
                    'color' => $v->color,
                    'stock' => (int)($v->inventory->stock ?? 0),
                ];
            })
        ];
    });

    return response()->json($data);
}

    
}

