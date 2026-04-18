<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Box;

class BoxController extends Controller
{
    public function index()
    {
        return response()->json(
            Box::query()
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
        );
    }
}