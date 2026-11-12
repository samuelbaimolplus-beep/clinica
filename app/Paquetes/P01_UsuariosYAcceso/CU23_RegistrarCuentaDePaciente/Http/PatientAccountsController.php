<?php

/** Entrada HTTP de PatientAccountsController. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Paquetes\P01_UsuariosYAcceso\CU23_RegistrarCuentaDePaciente\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class PatientAccountsController extends Controller
{
    /** Consulta cuentas de pacientes para supervisión administrativa autorizada. */
    public function patientAccounts(Request $request)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);

        return User::where('role', 'Paciente')->with('patient:id,first_name,last_name,status')
            ->orderByDesc('id')->get(['id', 'name', 'username', 'email', 'status', 'patient_id', 'email_verified_at', 'terms_accepted_at', 'terms_version']);
    }
}
