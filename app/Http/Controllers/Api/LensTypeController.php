<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LensType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LensTypeController extends Controller
{
    public function index(Request $request)
    {
        $q = LensType::query()
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
                  'active',
                  'created_at',
                  'updated_at',
              ])
        );
    }

    public function show($id)
    {
        $lensType = LensType::query()
            ->whereNull('deleted_at')
            ->findOrFail($id, [
                'id',
                'code',
                'name',
                'active',
                'created_at',
                'updated_at',
            ]);

        return response()->json($lensType);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'   => ['required', 'string', 'max:80', 'unique:lens_types,code'],
            'name'   => ['required', 'string', 'max:160'],
            'active' => ['nullable', 'boolean'],
        ]);

        $lensType = new LensType();
        $lensType->code = strtoupper(trim($data['code']));
        $lensType->name = trim($data['name']);
        $lensType->active = array_key_exists('active', $data) ? ((bool) $data['active'] ? 1 : 0) : 1;
        $lensType->save();

        return response()->json([
            'ok' => true,
            'lens_type' => $lensType->only([
                'id',
                'code',
                'name',
                'active',
                'created_at',
                'updated_at',
            ]),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $lensType = LensType::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $data = $request->validate([
            'code'   => ['sometimes', 'string', 'max:80', Rule::unique('lens_types', 'code')->ignore($lensType->id)],
            'name'   => ['sometimes', 'string', 'max:160'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('code', $data)) {
            $lensType->code = strtoupper(trim($data['code']));
        }

        if (array_key_exists('name', $data)) {
            $lensType->name = trim($data['name']);
        }

        if (array_key_exists('active', $data)) {
            $lensType->active = (bool) $data['active'] ? 1 : 0;
        }

        $lensType->save();

        return response()->json([
            'ok' => true,
            'lens_type' => $lensType->only([
                'id',
                'code',
                'name',
                'active',
                'created_at',
                'updated_at',
            ]),
        ]);
    }

    public function destroy($id)
    {
        $lensType = LensType::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $lensType->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Tipo de lente eliminado correctamente.',
        ]);
    }
}