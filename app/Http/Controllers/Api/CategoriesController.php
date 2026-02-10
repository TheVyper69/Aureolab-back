<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;

class CategoriesController extends Controller
{
    public function index()
    {
        // retorna activas y no borradas
        $cats = Category::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id','code','name','description']);

        return response()->json($cats);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required','string','max:40','unique:categories,code'],
            'name' => ['required','string','max:80'],
            'description' => ['nullable','string'],
        ]);

        $cat = Category::create($data);

        return response()->json($cat, 201);
    }

    public function update(Request $request, $id)
    {
        $cat = Category::query()->whereNull('deleted_at')->findOrFail($id);

        $data = $request->validate([
            'code' => ['required','string','max:40',"unique:categories,code,{$cat->id}"],
            'name' => ['required','string','max:80'],
            'description' => ['nullable','string'],
        ]);

        $cat->update($data);

        return response()->json($cat);
    }

    public function destroy($id)
    {
        $cat = Category::query()->whereNull('deleted_at')->findOrFail($id);
        $cat->delete();

        return response()->json(['ok'=>true]);
    }
}
