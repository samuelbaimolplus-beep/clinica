<?php

/** Entrada HTTP de UserController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Http;

use App\Compartido\Catalogos\Servicios\CatalogService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use App\Paquetes\P01_UsuariosYAcceso\CU03_GestionarUsuariosYRoles\Validacion\SaveUserRequest;

class UserController extends Controller
{
    /** Consulta el listado y sus filtros; la ruta aplica los permisos del módulo. */
    public function listar()
    {
        return User::where('role', '<>', 'Paciente')->when(request()->user()->role !== 'Superadmin', fn ($consulta) => $consulta->where('role', '<>', 'Superadmin'))->orderBy('id')->get();
    }

    /** Recupera el detalle autorizado del registro sin modificar su persistencia. */
    public function consultarDetalle(int $id)
    {
        return User::where('role', '<>', 'Paciente')->when(request()->user()->role !== 'Superadmin', fn ($consulta) => $consulta->where('role', '<>', 'Superadmin'))->findOrFail($id);
    }

    /** Coordina la validación y persistencia de esta operación; delega las reglas del dominio al servicio correspondiente. */
    public function guardar(SaveUserRequest $solicitud, CatalogService $servicio, ?int $id = null)
    {
        $registro = $id ? User::findOrFail($id) : new User;

        $registro = $servicio->guardar($registro, $solicitud->validated(), $solicitud->user());
        if (! $registro->email_verified_at) {
            $registro->setAttribute('activation_email_sent', app(EmailActivation::class)->enviar($registro));
        }

        return response()->json($registro, $id ? 200 : 201)->header('Cache-Control', 'no-store');
    }
}
