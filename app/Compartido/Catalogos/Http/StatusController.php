<?php

/** Entrada HTTP de StatusController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Compartido\Catalogos\Http;

use App\Compartido\Catalogos\Servicios\CatalogService;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusController extends Controller
{
    /** Atiende la operación única de esta clase usando las dependencias resueltas por Laravel. */
    public function __invoke(Request $solicitud, CatalogService $servicio, int $id)
    {
        $catalogo = $solicitud->route('catalog');
        $modelo = match ($catalogo) {
            'users' => User::class, 'doctors' => Doctor::class, 'specialties' => Specialty::class, 'patients' => Patient::class,
            default => abort(404),
        };
        abort_unless($solicitud->user()->can('manage-'.$catalogo), 403);
        $datos = $solicitud->validate(['status' => ['required', Rule::in(['Activo', 'Inactivo'])]]);

        return $servicio->cambiarEstado($modelo::findOrFail($id), $datos['status'], $solicitud->user());
    }
}
