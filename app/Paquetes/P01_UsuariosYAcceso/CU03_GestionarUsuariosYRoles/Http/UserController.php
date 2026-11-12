<?php

/** Entrada HTTP de UserController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Validacion\SaveUserRequest;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use App\Compartido\Catalogos\Servicios\CatalogService;

class UserController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function index()
    {
        return User::where('role', '<>', 'Paciente')->when(request()->user()->role !== 'Superadmin', fn ($q) => $q->where('role', '<>', 'Superadmin'))->orderBy('id')->get();
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function show(int $id)
    {
        return User::where('role', '<>', 'Paciente')->when(request()->user()->role !== 'Superadmin', fn ($q) => $q->where('role', '<>', 'Superadmin'))->findOrFail($id);
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function save(SaveUserRequest $request, CatalogService $service, ?int $id = null)
    {
        $record = $id ? User::findOrFail($id) : new User;

        $record = $service->save($record, $request->validated(), $request->user());
        if (! $record->email_verified_at) {
            $record->setAttribute('activation_email_sent', app(EmailActivation::class)->send($record));
        }

        return response()->json($record, $id ? 200 : 201)->header('Cache-Control', 'no-store');
    }
}
