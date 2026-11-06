<?php

namespace App\Http\Controllers;

use App\Http\Requests\Patients\UpdateContactRequest;
use App\Services\Patients\UpdatePatientContact;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return ['user' => $user, 'patient' => $user->role === 'Paciente' ? $user->patient : null,
            'doctor' => $user->role === 'Médico' ? $user->doctor()->with('specialties')->first() : null];
    }

    public function update(UpdateContactRequest $request, UpdatePatientContact $service)
    {
        $service->handle($request->user(), $request->validated());
        $request->user()->unsetRelation('patient');

        return $this->show($request);
    }
}
