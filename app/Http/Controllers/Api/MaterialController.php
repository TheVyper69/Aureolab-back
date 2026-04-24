<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $q = Material::query()
            ->whereNull('deleted_at');

        if ($request->filled('active')) {
            $active = filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (!is_null($active)) {
                $q->where('active', $active ? 1 : 0);
            }
        }

        return response()->json(
            $q->orderBy('name')
              ->get([
                  'id',
                  'code',
                  'name',
                  'description',
                  'active',
                  'created_at',
                  'updated_at',
              ])
        );
    }

    public function show($id)
    {
        $material = Material::query()
            ->whereNull('deleted_at')
            ->findOrFail($id, [
                'id',
                'code',
                'name',
                'description',
                'active',
                'created_at',
                'updated_at',
            ]);

        return response()->json($material);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'unique:materials,code'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $material = new Material();
        $material->code = strtoupper(trim($data['code']));
        $material->name = trim($data['name']);
        $material->description = array_key_exists('description', $data)
            ? (trim((string) $data['description']) ?: null)
            : null;
        $material->active = array_key_exists('active', $data)
            ? ((bool) $data['active'] ? 1 : 0)
            : 1;

        $material->save();

        return response()->json([
            'ok' => true,
            'material' => $material->only([
                'id',
                'code',
                'name',
                'description',
                'active',
                'created_at',
                'updated_at',
            ]),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $material = Material::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:80', Rule::unique('materials', 'code')->ignore($material->id)],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('code', $data)) {
            $material->code = strtoupper(trim($data['code']));
        }

        if (array_key_exists('name', $data)) {
            $material->name = trim($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $material->description = trim((string) $data['description']) ?: null;
        }

        if (array_key_exists('active', $data)) {
            $material->active = (bool) $data['active'] ? 1 : 0;
        }

        $material->save();

        return response()->json([
            'ok' => true,
            'material' => $material->only([
                'id',
                'code',
                'name',
                'description',
                'active',
                'created_at',
                'updated_at',
            ]),
        ]);
    }

    public function destroy($id)
    {
        $material = Material::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $material->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Material eliminado correctamente.',
        ]);
    }
}