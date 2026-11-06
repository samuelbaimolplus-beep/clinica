<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RegisterPatientRequest;
use App\Services\Auth\SessionSecurity;
use App\Services\Patients\RegisterPatient;
use Illuminate\Support\Facades\Auth;

class PatientRegistrationController extends Controller
{
    public function __invoke(RegisterPatientRequest $request, RegisterPatient $service)
    {
        $user = $service->handle($request->validated());
        Auth::login($user);
        $request->session()->regenerate();
        app(SessionSecurity::class)->remember($request, $user);

        return response()->json(['user' => $user, 'csrf' => csrf_token()], 201);
    }
}
