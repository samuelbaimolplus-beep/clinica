<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use Illuminate\Http\Request;

class ReferenceController extends Controller
{
    public function __invoke(Request $request)
    {
        // RN12: el paciente nunca descarga padrones ni datos de otros pacientes.
        $role = $request->user()->role;
        if ($role === 'Paciente') {
            return ['doctors' => [], 'specialties' => [], 'patients' => []];
        }

        return [
            'doctors' => Doctor::with('specialties')->when($role === 'Médico', fn ($q) => $q->whereKey($request->user()->doctor_id))->orderBy('last_name')->get(),
            'specialties' => Specialty::orderBy('name')->get(),
            'patients' => $request->user()->can('manage-patients')
                ? Patient::orderBy('last_name')->get() : [],
        ];
    }
}
