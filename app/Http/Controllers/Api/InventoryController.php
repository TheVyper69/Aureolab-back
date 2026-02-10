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
            'category:id,name',
            'inventory:id,product_id,stock',
            'variants.inventory'
        ])
        ->where('active', 1)
        ->get();

        $data = $products->map(function ($p) {
            $stock = $p->inventory->stock ?? 0;

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'description' => $p->description,
                'category' => $p->category?->name,
                'sale_price' => $p->sale_price,
                'buy_price' => $p->buy_price,
                'stock' => $stock,
                'critical' => $stock <= $p->min_stock,
                'image' => $p->image_filename ? url("/api/products/{$p->id}/image") : null,

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
                        'stock' => $v->inventory->stock ?? 0,
                    ];
                })
            ];
        });

        return response()->json($data);
    }
    
}

