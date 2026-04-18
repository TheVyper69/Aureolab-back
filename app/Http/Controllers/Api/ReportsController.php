<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    private function baseOrdersQuery(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $query = DB::table('orders as o')
            ->whereNull('o.deleted_at')
            ->where('o.payment_status', 'pagado');

        if ($from) {
            $query->whereDate('o.created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('o.created_at', '<=', $to);
        }

        return $query;
    }

    public function dashboard(Request $request)
    {
        $row = $this->baseOrdersQuery($request)
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(o.total), 0) as income')
            ->first();

        $orders = (int) ($row->orders_count ?? 0);
        $income = (float) ($row->income ?? 0);
        $avg = $orders > 0 ? ($income / $orders) : 0;

        return response()->json([
            'ok' => true,
            'orders' => $orders,
            'income' => round($income, 2),
            'avg' => round($avg, 2),
        ]);
    }

    public function ordersByDay(Request $request)
    {
        $rows = $this->baseOrdersQuery($request)
            ->selectRaw('DATE(o.created_at) as day, COUNT(*) as qty, COALESCE(SUM(o.total), 0) as total')
            ->groupBy(DB::raw('DATE(o.created_at)'))
            ->orderBy('day')
            ->get();

        return response()->json(
            $rows->map(function ($r) {
                return [
                    'day' => $r->day,
                    'qty' => (int) $r->qty,
                    'total' => round((float) $r->total, 2),
                ];
            })
        );
    }

    public function ordersPaymentMethods(Request $request)
    {
        $rows = $this->baseOrdersQuery($request)
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'o.payment_method_id')
            ->selectRaw('
                o.payment_method_id,
                COALESCE(pm.label, CONCAT("Método #", o.payment_method_id)) as label,
                COUNT(*) as qty,
                COALESCE(SUM(o.total), 0) as total
            ')
            ->groupBy('o.payment_method_id', 'pm.label')
            ->orderByDesc('total')
            ->get();

        return response()->json(
            $rows->map(function ($r) {
                return [
                    'payment_method_id' => $r->payment_method_id ? (int) $r->payment_method_id : null,
                    'label' => $r->label,
                    'qty' => (int) $r->qty,
                    'total' => round((float) $r->total, 2),
                ];
            })
        );
    }

    public function ordersTopProducts(Request $request)
    {
        $limit = max(1, min(20, (int) $request->query('limit', 10)));

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereNull('o.deleted_at')
            ->whereNull('oi.deleted_at')
            ->where('o.payment_status', 'pagado')
            ->when($request->query('from'), function ($q) use ($request) {
                $q->whereDate('o.created_at', '>=', $request->query('from'));
            })
            ->when($request->query('to'), function ($q) use ($request) {
                $q->whereDate('o.created_at', '<=', $request->query('to'));
            })
            ->selectRaw('
                oi.product_id,
                COALESCE(p.name, CONCAT("Producto ", oi.product_id)) as name,
                COALESCE(p.sku, "") as sku,
                COALESCE(SUM(oi.qty), 0) as qty,
                COALESCE(SUM(oi.line_total), 0) as total
            ')
            ->groupBy('oi.product_id', 'p.name', 'p.sku')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();

        return response()->json(
            $rows->map(function ($r) {
                return [
                    'product_id' => (int) $r->product_id,
                    'name' => $r->name,
                    'sku' => $r->sku,
                    'qty' => (int) $r->qty,
                    'total' => round((float) $r->total, 2),
                ];
            })
        );
    }
}