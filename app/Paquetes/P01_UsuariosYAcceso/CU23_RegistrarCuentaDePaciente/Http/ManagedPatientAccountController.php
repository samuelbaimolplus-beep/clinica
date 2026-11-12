<?php

/** Entrada HTTP de ManagedPatientAccountController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http;

use App\Http\Controllers\Controller;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion\CreatePatientAccountRequest;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion\RegisterManagedPatientRequest;
use App\Models\Patient;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Servicios\CreatePatientAccount;

class ManagedPatientAccountController extends Controller
{
    /** Registra dependencias o el alta asistida según la responsabilidad de esta clase. */
    public function register(RegisterManagedPatientRequest $request, CreatePatientAccount $service)
    {
        $patient = $service->register($request->validated(), $request->user());
        $patient->setAttribute('activation_email_sent', app(EmailActivation::class)->send($patient->account));

        return response()->json($patient, 201)
            ->header('Cache-Control', 'no-store');
    }

    /** Registra la operación solicitada después de validar entradas y permisos. */
    public function store(CreatePatientAccountRequest $request, CreatePatientAccount $service, int $id)
    {
        $user = $service->link(Patient::findOrFail($id), $request->validated(), $request->user());

        $sent = app(EmailActivation::class)->send($user);

        return response()->json(['user' => $user, 'activation_email_sent' => $sent,
            'message' => $sent ? 'Cuenta creada. El paciente debe confirmar el código enviado a su correo para activarla.'
                : 'Cuenta creada pendiente de activación. Solicite el código desde Activar mi cuenta.'], 201)
            ->header('Cache-Control', 'no-store');
    }
}
