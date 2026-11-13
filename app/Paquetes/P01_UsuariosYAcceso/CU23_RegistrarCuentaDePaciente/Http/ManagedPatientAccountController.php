<?php

/** Entrada HTTP de ManagedPatientAccountController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Servicios\CreatePatientAccount;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion\CreatePatientAccountRequest;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion\RegisterManagedPatientRequest;

class ManagedPatientAccountController extends Controller
{
    /** Registra al paciente con su cuenta y solicita el envío del código de activación. */
    public function registrar(RegisterManagedPatientRequest $solicitud, CreatePatientAccount $servicio)
    {
        $paciente = $servicio->registrar($solicitud->validated(), $solicitud->user());
        $paciente->setAttribute('activation_email_sent', app(EmailActivation::class)->enviar($paciente->account));

        return response()->json($paciente, 201)
            ->header('Cache-Control', 'no-store');
    }

    /** Crea y vincula una cuenta al paciente existente después de validar entradas y permisos. */
    public function crearCuentaParaPaciente(CreatePatientAccountRequest $solicitud, CreatePatientAccount $servicio, int $id)
    {
        $usuario = $servicio->vincular(Patient::findOrFail($id), $solicitud->validated(), $solicitud->user());

        $enviado = app(EmailActivation::class)->enviar($usuario);

        return response()->json(['user' => $usuario, 'activation_email_sent' => $enviado,
            'message' => $enviado ? 'Cuenta creada. El paciente debe confirmar el código enviado a su correo para activarla.'
                : 'Cuenta creada pendiente de activación. Solicite el código desde Activar mi cuenta.'], 201)
            ->header('Cache-Control', 'no-store');
    }
}
