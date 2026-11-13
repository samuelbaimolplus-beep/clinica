<?php

/** Entrada HTTP de ControladorAltaCuentaPaciente. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\ActivacionPorCorreo;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Servicios\CrearCuentaPaciente;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion\SolicitudCrearCuentaPaciente;
use App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Validacion\SolicitudRegistrarPacienteConCuenta;

class ControladorAltaCuentaPaciente extends Controller
{
    /** Registra al paciente con su cuenta y solicita el envío del código de activación. */
    public function registrar(SolicitudRegistrarPacienteConCuenta $solicitud, CrearCuentaPaciente $servicio)
    {
        $paciente = $servicio->registrar($solicitud->validated(), $solicitud->user());
        $paciente->setAttribute('activation_email_sent', app(ActivacionPorCorreo::class)->enviar($paciente->account));

        return response()->json($paciente, 201)
            ->header('Cache-Control', 'no-store');
    }

    /** Crea y vincula una cuenta al paciente existente después de validar entradas y permisos. */
    public function crearCuentaParaPaciente(SolicitudCrearCuentaPaciente $solicitud, CrearCuentaPaciente $servicio, int $id)
    {
        $usuario = $servicio->vincular(Patient::findOrFail($id), $solicitud->validated(), $solicitud->user());

        $enviado = app(ActivacionPorCorreo::class)->enviar($usuario);

        return response()->json(['user' => $usuario, 'activation_email_sent' => $enviado,
            'message' => $enviado ? 'Cuenta creada. El paciente debe confirmar el código enviado a su correo para activarla.'
                : 'Cuenta creada pendiente de activación. Solicite el código desde Activar mi cuenta.'], 201)
            ->header('Cache-Control', 'no-store');
    }
}
