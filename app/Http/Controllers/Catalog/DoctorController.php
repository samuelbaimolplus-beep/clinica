<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveDoctorRequest;
use App\Models\Doctor;
use App\Services\Catalog\CatalogImpact;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150']);
        $search = mb_strtolower(trim($data['search'] ?? ''));

        return Doctor::with('specialties')->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw("strpos(lower(first_name || ' ' || last_name), ?) > 0", [$search])
            ->orWhereHas('specialties', fn ($specialty) => $specialty->whereRaw('strpos(lower(name), ?) > 0', [$search]))))
            ->orderBy('id')->get();
    }

    public function show(int $id, CatalogImpact $impact)
    {
        $doctor = Doctor::with('specialties')->findOrFail($id);

        return $doctor->setAttribute('affected_appointments', $impact->affected(doctorId: $id));
    }

    public function save(SaveDoctorRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? Doctor::findOrFail($id) : new Doctor;

        return response()->json($service->save($record, $request->validated(), $request->user()), $id ? 200 : 201);
    }
}
