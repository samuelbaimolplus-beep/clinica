<?php

/** Entrada HTTP de ControladorEstadoCatalogos. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Compartido\Catalogos\Http;

use App\Compartido\Catalogos\Servicios\ServicioCatalogos;
use App\Http\Controllers\Controller;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControladorEstadoCatalogos extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, ServicioCatalogos $servicio, int $id)
    {
        $catalogo = $solicitud->route('catalog');
        $modelo = match ($catalogo) {
            'users' => Usuario::class, 'doctors' => Medico::class, 'specialties' => Especialidad::class, 'patients' => Paciente::class,
            default => abort(404),
        };
        abort_unless($solicitud->user()->can('manage-'.$catalogo), 403);
        $datos = $solicitud->validate(['status' => ['required', Rule::in(['Activo', 'Inactivo'])]]);

        return $servicio->cambiarEstado($modelo::findOrFail($id), $datos['status'], $solicitud->user());
    }
}
