<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $q = Supplier::query()
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
                  'name',
                  'phone',
                  'email',
                  'notes',
                  'active',
                  'created_at',
                  'updated_at',
              ])
        );
    }

    public function show($id)
    {
        $supplier = Supplier::query()
            ->whereNull('deleted_at')
            ->findOrFail($id, [
                'id',
                'name',
                'phone',
                'email',
                'notes',
                'active',
                'created_at',
                'updated_at',
            ]);

        return response()->json($supplier);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:160'],
            'phone'  => ['nullable', 'string', 'max:40'],
            'email'  => ['nullable', 'email', 'max:190'],
            'notes'  => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $supplier = new Supplier();
        $supplier->name = trim($data['name']);
        $supplier->phone = array_key_exists('phone', $data) ? (trim((string) $data['phone']) ?: null) : null;
        $supplier->email = array_key_exists('email', $data) ? (trim((string) $data['email']) ?: null) : null;
        $supplier->notes = array_key_exists('notes', $data) ? (trim((string) $data['notes']) ?: null) : null;
        $supplier->active = array_key_exists('active', $data) ? ((bool) $data['active'] ? 1 : 0) : 1;
        $supplier->save();

        return response()->json([
            'ok' => true,
            'supplier' => $supplier->only([
                'id',
                'name',
                'phone',
                'email',
                'notes',
                'active',
                'created_at',
                'updated_at',
            ]),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:160'],
            'phone'  => ['nullable', 'string', 'max:40'],
            'email'  => ['nullable', 'email', 'max:190'],
            'notes'  => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $supplier->name = trim($data['name']);
        }

        if (array_key_exists('phone', $data)) {
            $supplier->phone = trim((string) $data['phone']) ?: null;
        }

        if (array_key_exists('email', $data)) {
            $supplier->email = trim((string) $data['email']) ?: null;
        }

        if (array_key_exists('notes', $data)) {
            $supplier->notes = trim((string) $data['notes']) ?: null;
        }

        if (array_key_exists('active', $data)) {
            $supplier->active = (bool) $data['active'] ? 1 : 0;
        }

        $supplier->save();

        return response()->json([
            'ok' => true,
            'supplier' => $supplier->only([
                'id',
                'name',
                'phone',
                'email',
                'notes',
                'active',
                'created_at',
                'updated_at',
            ]),
        ]);
    }

    public function destroy($id)
    {
        $supplier = Supplier::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $supplier->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Proveedor eliminado correctamente.',
        ]);
    }
}