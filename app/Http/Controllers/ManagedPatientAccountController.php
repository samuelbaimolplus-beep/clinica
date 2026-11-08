<?php

namespace App\Http\Controllers;

use App\Http\Requests\Patients\CreatePatientAccountRequest;
use App\Http\Requests\Patients\RegisterManagedPatientRequest;
use App\Models\Patient;
use App\Services\Auth\EmailActivation;
use App\Services\Patients\CreatePatientAccount;

class ManagedPatientAccountController extends Controller
{
    public function register(RegisterManagedPatientRequest $request, CreatePatientAccount $service)
    {
        $patient = $service->register($request->validated(), $request->user());
        $patient->setAttribute('activation_email_sent', app(EmailActivation::class)->send($patient->account));

        return response()->json($patient, 201)
            ->header('Cache-Control', 'no-store');
    }

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
