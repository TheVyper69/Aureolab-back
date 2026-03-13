<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class OrdersReportController extends Controller
{

    public function salesByDay()
    {
        $rows = DB::table('orders')
            ->selectRaw('DATE(created_at) as day, SUM(total) as total, COUNT(*) as qty')
            ->whereNull('deleted_at')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get();

        return response()->json($rows);
    }

    public function paymentMethods()
    {
        $rows = DB::table('orders')
            ->selectRaw('payment_method_id, COUNT(*) as qty, SUM(total) as total')
            ->whereNull('deleted_at')
            ->groupBy('payment_method_id')
            ->get();

        return response()->json($rows);
    }

    public function topProducts()
    {
        $rows = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->selectRaw('p.name, SUM(oi.qty) as qty, SUM(oi.line_total) as total')
            ->groupBy('oi.product_id', 'p.name')
            ->orderByDesc('qty')
            ->limit(10)
            ->get();

        return response()->json($rows);
    }
}