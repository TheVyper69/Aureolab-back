<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TreatmentsController extends Controller
{
    public function index(Request $request)
    {
        $rows = DB::table('treatments')
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'description',
            ]);

        return response()->json($rows);
    }

    public function byProduct(Request $request, $id)
    {
        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.id', (int) $id)
            ->select(
                'p.id',
                'p.category_id',
                'c.code as category_code',
                'c.name as category_name'
            )
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404);
        }

        $code = strtoupper((string) ($product->category_code ?? ''));
        $name = strtoupper((string) ($product->category_name ?? ''));

        $isMica = $code === 'MICAS'
            || $code === 'MICA'
            || $name === 'MICAS'
            || $name === 'MICA'
            || str_contains($name, 'MICA');

        if (!$isMica) {
            return response()->json([]);
        }

        $query = DB::table('treatments as t')
            ->where('t.active', 1)
            ->whereNull('t.deleted_at');

        $hasPivot = DB::table('product_treatments')
            ->where('product_id', (int) $id)
            ->exists();

        if ($hasPivot) {
            $query->join('product_treatments as pt', function ($join) use ($id) {
                $join->on('pt.treatment_id', '=', 't.id')
                    ->where('pt.product_id', '=', (int) $id);
            });
        }

        $rows = $query
            ->orderBy('t.name')
            ->get([
                't.id',
                't.name',
                't.code',
                't.description',
            ]);

        return response()->json($rows);
    }
}