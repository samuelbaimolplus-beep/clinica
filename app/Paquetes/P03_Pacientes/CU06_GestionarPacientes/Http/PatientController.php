<?php

/** Entrada HTTP de PatientController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Http;

use App\Compartido\Catalogos\Servicios\CatalogService;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Paquetes\P03_Pacientes\CU06_GestionarPacientes\Validacion\SavePatientRequest;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function consultarDetalle(int $id)
    {
        return Patient::with('account:id,patient_id,username,email,status,email_verified_at')->findOrFail($id);
    }

    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $solicitud)
    {
        $datos = $solicitud->validate(['search' => 'nullable|string|max:150']);
        $busqueda = mb_strtolower(trim($datos['search'] ?? ''));

        return Patient::with('account:id,patient_id,username,email,status,email_verified_at')->when($busqueda !== '', fn ($consulta) => $consulta->where(function ($consulta) use ($busqueda) {
            // strpos interpreta la búsqueda literalmente, sin comodines SQL proporcionados por el usuario.
            $consulta->whereRaw('strpos(lower(coalesce(identity, ?)), ?) > 0', ['', $busqueda])
                ->orWhereRaw('strpos(lower(first_name), ?) > 0', [$busqueda])
                ->orWhereRaw('strpos(lower(last_name), ?) > 0', [$busqueda]);
        }))->orderBy('id')->get();
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(SavePatientRequest $solicitud, CatalogService $servicio, ?int $id = null)
    {
        $registro = $id ? Patient::findOrFail($id) : new Patient;

        return response()->json($servicio->guardar($registro, $solicitud->validated(), $solicitud->user()), $id ? 200 : 201);
    }
}
