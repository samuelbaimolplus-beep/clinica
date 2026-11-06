<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveSpecialtyRequest;
use App\Models\Specialty;
use App\Services\Catalog\CatalogImpact;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\Request;

class SpecialtyController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150']);
        $search = mb_strtolower(trim($data['search'] ?? ''));

        return Specialty::when($search !== '', fn ($query) => $query->whereRaw('strpos(lower(name), ?) > 0', [$search]))->orderBy('id')->get();
    }

    public function show(int $id, CatalogImpact $impact)
    {
        return Specialty::findOrFail($id)->setAttribute('affected_appointments', $impact->affected(specialtyId: $id));
    }

    public function save(SaveSpecialtyRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? Specialty::findOrFail($id) : new Specialty;

        return response()->json($service->save($record, $request->validated(), $request->user()), $id ? 200 : 201);
    }
}
