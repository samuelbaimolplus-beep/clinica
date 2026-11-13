<?php

/** Entrada HTTP de ControladorMedicos. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Http;

use App\Compartido\Catalogos\Servicios\ImpactoCatalogos;
use App\Compartido\Catalogos\Servicios\ServicioCatalogos;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU04_GestionarMedicos\Validacion\SolicitudGuardarMedico;
use Illuminate\Http\Request;

class ControladorMedicos extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $solicitud)
    {
        $datos = $solicitud->validate(['search' => 'nullable|string|max:150']);
        $busqueda = mb_strtolower(trim($datos['search'] ?? ''));

        return Doctor::with('specialties')->when($busqueda !== '', fn ($consultaDatos) => $consultaDatos->where(fn ($consultaDatos) => $consultaDatos->whereRaw("strpos(lower(first_name || ' ' || last_name), ?) > 0", [$busqueda])
            ->orWhereHas('specialties', fn ($especialidad) => $especialidad->whereRaw('strpos(lower(name), ?) > 0', [$busqueda]))))
            ->orderBy('id')->get();
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function consultarDetalle(int $id, ImpactoCatalogos $impacto)
    {
        $medico = Doctor::with('specialties')->findOrFail($id);

        return $medico->setAttribute('affected_appointments', $impacto->citasAfectadas(medicoId: $id));
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(SolicitudGuardarMedico $solicitud, ServicioCatalogos $servicio, ?int $id = null)
    {
        $registro = $id ? Doctor::findOrFail($id) : new Doctor;

        return response()->json($servicio->guardar($registro, $solicitud->validated(), $solicitud->user()), $id ? 200 : 201);
    }
}
