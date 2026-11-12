<?php

/** Entrada HTTP de SpecialtyController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P02_PersonalMedicoYEspecialidades\CU05_GestionarEspecialidades\Validacion\SaveSpecialtyRequest;
use App\Models\Specialty;
use App\Compartido\Catalogos\Servicios\CatalogImpact;
use App\Compartido\Catalogos\Servicios\CatalogService;
use Illuminate\Http\Request;

class SpecialtyController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150']);
        $search = mb_strtolower(trim($data['search'] ?? ''));

        return Specialty::when($search !== '', fn ($query) => $query->whereRaw('strpos(lower(name), ?) > 0', [$search]))->orderBy('id')->get();
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function show(int $id, CatalogImpact $impact)
    {
        return Specialty::findOrFail($id)->setAttribute('affected_appointments', $impact->affected(specialtyId: $id));
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function save(SaveSpecialtyRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? Specialty::findOrFail($id) : new Specialty;

        $data = $request->validated();
        if (array_key_exists('fee_amount', $data)) {
            [$whole, $fraction] = array_pad(explode('.', (string) $data['fee_amount'], 2), 2, '');
            $data['fee_minor'] = $data['fee_amount'] === null ? null : (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            unset($data['fee_amount']);
        }

        return response()->json($service->save($record, $data, $request->user()), $id ? 200 : 201);
    }
}
