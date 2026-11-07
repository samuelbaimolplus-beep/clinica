<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RegisterPatientRequest;
use App\Services\Patients\RegisterPatient;

class PatientRegistrationController extends Controller
{
    public function __invoke(RegisterPatientRequest $request, RegisterPatient $service)
    {
        $user = $service->handle($request->validated());

        return response()->json(['user' => $user, 'csrf' => csrf_token(),
            'message' => 'Cuenta creada correctamente. Inicie sesión con sus credenciales.'], 201);
    }
}
