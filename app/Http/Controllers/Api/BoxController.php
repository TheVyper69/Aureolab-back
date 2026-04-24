<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Box;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoxController extends Controller
{
    public function index(Request $request)
    {
        $q = Box::query()
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
                  'notes',
                  'active',
                  'created_at',
                  'updated_at',
              ])
        );
    }

    public function show($id)
    {
        $box = Box::query()
            ->whereNull('deleted_at')
            ->findOrFail($id, [
                'id',
                'code',
                'name',
                'notes',
                'active',
                'created_at',
                'updated_at',
            ]);

        return response()->json($box);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'   => ['required', 'string', 'max:60', 'unique:boxes,code'],
            'name'   => ['required', 'string', 'max:160'],
            'notes'  => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $box = new Box();
        $box->code = strtoupper(trim($data['code']));
        $box->name = trim($data['name']);
        $box->notes = array_key_exists('notes', $data) ? (trim((string) $data['notes']) ?: null) : null;
        $box->active = array_key_exists('active', $data) ? ((bool) $data['active'] ? 1 : 0) : 1;
        $box->save();

        return response()->json([
            'ok' => true,
            'box' => $box->only([
                'id',
                'code',
                'name',
                'notes',
                'active',
                'created_at',
                'updated_at',
            ]),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $box = Box::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $data = $request->validate([
            'code'   => ['sometimes', 'string', 'max:60', Rule::unique('boxes', 'code')->ignore($box->id)],
            'name'   => ['sometimes', 'string', 'max:160'],
            'notes'  => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('code', $data)) {
            $box->code = strtoupper(trim($data['code']));
        }

        if (array_key_exists('name', $data)) {
            $box->name = trim($data['name']);
        }

        if (array_key_exists('notes', $data)) {
            $box->notes = trim((string) $data['notes']) ?: null;
        }

        if (array_key_exists('active', $data)) {
            $box->active = (bool) $data['active'] ? 1 : 0;
        }

        $box->save();

        return response()->json([
            'ok' => true,
            'box' => $box->only([
                'id',
                'code',
                'name',
                'notes',
                'active',
                'created_at',
                'updated_at',
            ]),
        ]);
    }

    public function destroy($id)
    {
        $box = Box::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $box->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Box eliminado correctamente.',
        ]);
    }
}