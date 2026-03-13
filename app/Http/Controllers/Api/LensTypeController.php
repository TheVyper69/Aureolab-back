<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LensType;

class LensTypeController extends Controller
{
    public function index()
    {
        return response()->json(
            LensType::query()
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
        );
    }
}