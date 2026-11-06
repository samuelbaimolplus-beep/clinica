<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientAccountInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Recepción verifica presencialmente la identidad antes de habilitar una vinculación. */
class PatientAccountInvitationController extends Controller
{
    public function __invoke(Request $request, int $id)
    {
        $request->validate(['identity_verified' => 'required|accepted']);

        return DB::transaction(function () use ($request, $id) {
            $patient = Patient::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($patient->status !== 'Activo' || User::where('patient_id', $id)->exists()) {
                throw ValidationException::withMessages(['patient' => 'El paciente está inactivo o ya tiene una cuenta vinculada.']);
            }
            // Un código nuevo revoca cualquier código anterior, sin almacenar el secreto en texto plano.
            PatientAccountInvitation::where('patient_id', $id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $token = Str::random(48);
            $invitation = PatientAccountInvitation::create(['patient_id' => $id, 'issued_by' => $request->user()->id,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48)]);
            app(AuditLogger::class)->record('patients', 'account_link_authorized', $patient);

            return response()->json(['activation_code' => $token, 'expires_at' => $invitation->expires_at], 201)
                ->header('Cache-Control', 'no-store');
        });
    }
}
