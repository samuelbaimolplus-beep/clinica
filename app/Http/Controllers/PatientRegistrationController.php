<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RegisterPatientRequest;
use App\Notifications\VerifyClinicEmail;
use App\Services\Patients\RegisterPatient;

class PatientRegistrationController extends Controller
{
    public function __invoke(RegisterPatientRequest $request, RegisterPatient $service)
    {
        $user = $service->handle($request->validated());
        $user->notify(new VerifyClinicEmail);

        return response()->json(['user' => $user, 'csrf' => csrf_token(),
            'message' => 'Cuenta creada. Verifique el enlace enviado a su correo y luego inicie sesión.'], 201);
    }
}
