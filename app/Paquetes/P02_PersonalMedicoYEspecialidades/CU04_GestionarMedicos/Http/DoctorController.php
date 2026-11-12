<?php

/** Entrada HTTP de DoctorController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Validacion\SaveDoctorRequest;
use App\Models\Doctor;
use App\Compartido\Catalogos\Servicios\CatalogImpact;
use App\Compartido\Catalogos\Servicios\CatalogService;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150']);
        $search = mb_strtolower(trim($data['search'] ?? ''));

        return Doctor::with('specialties')->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw("strpos(lower(first_name || ' ' || last_name), ?) > 0", [$search])
            ->orWhereHas('specialties', fn ($specialty) => $specialty->whereRaw('strpos(lower(name), ?) > 0', [$search]))))
            ->orderBy('id')->get();
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function show(int $id, CatalogImpact $impact)
    {
        $doctor = Doctor::with('specialties')->findOrFail($id);

        return $doctor->setAttribute('affected_appointments', $impact->affected(doctorId: $id));
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function save(SaveDoctorRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? Doctor::findOrFail($id) : new Doctor;

        return response()->json($service->save($record, $request->validated(), $request->user()), $id ? 200 : 201);
    }
}
