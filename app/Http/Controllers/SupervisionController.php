<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\RescheduleRequest;
use App\Models\User;
use Illuminate\Http\Request;

/** Ampliación autorizada: supervisión global; las acciones personales conservan su actor. */
class SupervisionController extends Controller
{
    public function patientAccounts(Request $request)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);

        return User::where('role', 'Paciente')->with('patient:id,first_name,last_name,status')
            ->orderByDesc('id')->get(['id', 'name', 'username', 'email', 'status', 'patient_id', 'email_verified_at', 'terms_accepted_at', 'terms_version']);
    }

    public function payments(Request $request)
    {
        abort_unless($request->user()->role === 'Superadmin', 403);

        $payments = Payment::with('bookingRequest.patient:id,first_name,last_name', 'appointments.patient:id,first_name,last_name')->orderByDesc('id')->paginate(50)->toArray();
        $payments['exemptions'] = Appointment::where('complimentary', true)->with('patient:id,first_name,last_name')
            ->orderByDesc('id')->limit(50)->get(['id', 'patient_id', 'parent_appointment_id', 'date', 'start_time', 'status']);

        return $payments;
    }

    public function receptionInbox(Request $request)
    {
        abort_unless($request->user()->hasStaffRole('Recepcionista'), 403);
        $query = RescheduleRequest::where('status', 'pending');

        return response()->json(['pending' => (clone $query)->count(), 'latest_id' => $query->max('id')])->header('Cache-Control', 'no-store');
    }
}
