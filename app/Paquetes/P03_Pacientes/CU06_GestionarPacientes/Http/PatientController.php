<?php

/** Entrada HTTP de PatientController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Validacion\SavePatientRequest;
use App\Models\Patient;
use App\Compartido\Catalogos\Servicios\CatalogService;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function show(int $id)
    {
        return Patient::with('account:id,patient_id,username,email,status,email_verified_at')->findOrFail($id);
    }

    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150']);
        $search = mb_strtolower(trim($data['search'] ?? ''));

        return Patient::with('account:id,patient_id,username,email,status,email_verified_at')->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
            // strpos interpreta la búsqueda literalmente, sin comodines SQL proporcionados por el usuario.
            $q->whereRaw('strpos(lower(coalesce(identity, ?)), ?) > 0', ['', $search])
                ->orWhereRaw('strpos(lower(first_name), ?) > 0', [$search])
                ->orWhereRaw('strpos(lower(last_name), ?) > 0', [$search]);
        }))->orderBy('id')->get();
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function save(SavePatientRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? Patient::findOrFail($id) : new Patient;

        return response()->json($service->save($record, $request->validated(), $request->user()), $id ? 200 : 201);
    }
}
