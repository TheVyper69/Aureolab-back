<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoriesController extends Controller
{
    public function index()
    {
        return Category::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id','code','name','description']);
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
        $cat = Category::whereNull('deleted_at')->findOrFail($id);

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
        $cat = Category::whereNull('deleted_at')->findOrFail($id);

        // opcional: bloquear si hay productos usando esa categoría
        // if(\App\Models\Product::where('category_id',$cat->id)->whereNull('deleted_at')->exists()){
        //   return response()->json(['message'=>'No puedes borrar: hay productos en esta categoría'], 409);
        // }

        $cat->delete();
        return response()->json(['ok'=>true]);
    }
}
