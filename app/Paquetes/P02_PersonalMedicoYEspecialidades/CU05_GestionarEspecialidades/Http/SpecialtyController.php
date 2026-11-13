<?php

/** Entrada HTTP de SpecialtyController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Http;

use App\Compartido\Catalogos\Servicios\CatalogImpact;
use App\Compartido\Catalogos\Servicios\CatalogService;
use App\Http\Controllers\Controller;
use App\Models\Specialty;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Validacion\SaveSpecialtyRequest;
use Illuminate\Http\Request;

class SpecialtyController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar(Request $solicitud)
    {
        $datos = $solicitud->validate(['search' => 'nullable|string|max:150']);
        $busqueda = mb_strtolower(trim($datos['search'] ?? ''));

        return Specialty::when($busqueda !== '', fn ($consultaDatos) => $consultaDatos->whereRaw('strpos(lower(name), ?) > 0', [$busqueda]))->orderBy('id')->get();
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function consultarDetalle(int $id, CatalogImpact $impacto)
    {
        return Specialty::findOrFail($id)->setAttribute('affected_appointments', $impacto->citasAfectadas(especialidadId: $id));
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(SaveSpecialtyRequest $solicitud, CatalogService $servicio, ?int $id = null)
    {
        $registro = $id ? Specialty::findOrFail($id) : new Specialty;

        $datos = $solicitud->validated();
        if (array_key_exists('fee_amount', $datos)) {
            [$entero, $fraccion] = array_pad(explode('.', (string) $datos['fee_amount'], 2), 2, '');
            $datos['fee_minor'] = $datos['fee_amount'] === null ? null : (int) $entero * 100 + (int) str_pad($fraccion, 2, '0');
            unset($datos['fee_amount']);
        }

        return response()->json($servicio->guardar($registro, $datos, $solicitud->user()), $id ? 200 : 201);
    }
}
