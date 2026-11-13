<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\BookingRequest;
use App\Models\ClinicalSlot;
use App\Models\Doctor;
use App\Models\DoctorQuota;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Paquetes\P05_CitasMedicas\Compartido\Servicios\AppointmentService;
use App\Paquetes\P08_Auditoria\CU22_ConsultarBitacoraDeAuditoria\Servicios\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseRoutinesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Doctor $doctor;

    private Patient $patient;

    private Specialty $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->admin = $this->verifiedAccount(['name' => 'Administrador SQL', 'email' => 'admin@rutinas.test',
            'password' => 'ClaveSegura2026', 'role' => 'Administrador', 'status' => 'Activo']);
        $this->doctor = Doctor::create(['registration' => 'RUTINAS', 'first_name' => 'Ana', 'last_name' => 'Médica',
            'email' => 'medica@rutinas.test', 'phone' => '70000000', 'status' => 'Activo']);
        $this->patient = Patient::create(['identity' => 'RUTINAS', 'first_name' => 'Luis', 'last_name' => 'Paciente',
            'email' => 'paciente@rutinas.test', 'phone' => '70000001', 'birth_date' => '2000-01-01', 'sex' => 'Masculino']);
        $this->specialty = Specialty::create(['name' => 'Especialidad SQL', 'status' => 'Activo']);
    }

    private function selection(): array
    {
        return ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'specialty_id' => $this->specialty->id, 'created_by' => $this->admin->id,
            'date' => '2026-11-15', 'start_time' => '08:00', 'end_time' => '08:30',
            'clinical_slot_id' => ClinicalSlot::identify($this->doctor->id, '2026-11-15', '08:00', '08:30')];
    }

    private function reject(callable $operation, string $state = '23514'): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL aceptó una operación inválida.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }

    public function test_email_triggers_work_for_direct_sql_in_all_three_tables(): void
    {
        foreach (['users' => $this->admin, 'doctors' => $this->doctor, 'patients' => $this->patient] as $table => $model) {
            DB::table($table)->where('id', $model->id)->update(['email' => '  NUEVO@'.$table.'.test  ']);
            $this->assertSame('nuevo@'.$table.'.test', $model->fresh()->email);
        }
        DB::table('patients')->where('id', $this->patient->id)->update(['email' => '   ']);
        $this->assertNull($this->patient->fresh()->email);
        $this->reject(fn () => DB::table('users')->where('id', $this->admin->id)->update(['email' => '   ']), '23502');
        $this->assertSame('nuevo@users.test', $this->admin->fresh()->email);
    }

    public function test_slot_triggers_reject_mismatched_appointments_reservations_and_later_edits(): void
    {
        $selection = $this->selection();
        $this->reject(fn () => DB::table('appointments')->insert(array_replace($selection, ['date' => '2026-11-16'])));
        $booking = $selection + ['idempotency_key' => (string) Str::uuid(), 'modality' => 'online',
            'amount_minor' => 100, 'status' => 'held', 'expires_at' => now()->addMinutes(5), 'deadline_at' => now()->addMinutes(10)];
        $this->reject(fn () => DB::table('booking_requests')->insert(array_replace($booking, ['end_time' => '09:00'])));
        $a = Appointment::create($selection);
        $r = BookingRequest::create($booking);
        $this->reject(fn () => DB::table('appointments')->where('id', $a->id)->update(['date' => '2026-11-16']));
        $this->reject(fn () => DB::table('booking_requests')->where('id', $r->id)->update(['start_time' => '07:30']));
        $this->reject(fn () => DB::table('clinical_slots')->where('id', $selection['clinical_slot_id'])->update(['date' => '2026-11-16']));
        $this->assertSame('2026-11-15', $a->fresh()->date);
        $this->assertSame('2026-11-15', $r->fresh()->date);
        $this->assertSame('2026-11-15', ClinicalSlot::findOrFail($selection['clinical_slot_id'])->date);
    }

    public function test_slot_triggers_preserve_legacy_null_links_and_allow_unused_slot_edits(): void
    {
        $selection = $this->selection();
        DB::table('clinical_slots')->where('id', $selection['clinical_slot_id'])->update(['date' => '2026-11-16']);
        $a = Appointment::create(array_replace($selection, ['clinical_slot_id' => null]));
        $this->assertNull($a->clinical_slot_id);
        $this->assertSame('2026-11-16', ClinicalSlot::findOrFail($selection['clinical_slot_id'])->date);
    }

    public function test_audit_procedure_filters_details_and_remains_immutable_and_transactional(): void
    {
        $this->actingAs($this->admin);
        $before = AuditEvent::count();
        DB::statement('CALL public.registrar_auditoria(?, ?, ?, ?, ?, ?, ?::jsonb)', [
            $this->admin->id, 'sql', 'comprobacion', 'User', $this->admin->id, 'success',
            json_encode(['fields' => ['status'], 'password' => 'SECRETO', 'reason' => 'Prueba']),
        ]);
        $event = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame($before + 1, AuditEvent::count());
        $this->assertSame(['fields' => ['status'], 'reason' => 'Prueba'], $event->details);
        $this->reject(fn () => DB::table('audit_events')->where('id', $event->id)->delete(), 'P0001');
        $this->reject(fn () => DB::statement("CALL public.registrar_auditoria(NULL, 'sql', '', NULL, NULL, 'success', '{}'::jsonb)"));
        try {
            DB::transaction(function () {
                app(AuditLogger::class)->registrar('sql', 'revertido');
                throw new \RuntimeException('Revertir');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Revertir', $exception->getMessage());
        }
        $this->assertDatabaseMissing('audit_events', ['module' => 'sql', 'action' => 'revertido']);
    }

    public function test_event_procedure_is_used_by_application_and_enforces_foreign_keys(): void
    {
        $a = Appointment::create($this->selection());
        app(AppointmentService::class)->registrarEvento($a, $this->admin, 'Prueba del procedimiento', ['reason' => 'Evento SQL']);
        $this->assertSame(1, $a->events()->count());
        $this->assertSame(['reason' => 'Evento SQL'], $a->events()->firstOrFail()->details);
        $this->reject(fn () => DB::statement("CALL public.registrar_evento_cita(?, ?, 'Sin cita', '{}'::jsonb)", [-1, $this->admin->id]), '23503');
        $this->reject(fn () => DB::statement("CALL public.registrar_evento_cita(?, ?, 'JSON inválido', '[]'::jsonb)", [$a->id, $this->admin->id]));
    }

    public function test_quota_procedure_validates_actor_and_percentages_and_audits_only_changes(): void
    {
        $call = fn ($p, $o, $actor) => DB::statement('CALL public.configurar_cupos_medico(?, ?, ?, ?)', [$this->doctor->id, $p, $o, $actor]);
        $reception = $this->verifiedAccount(['name' => 'Recepción SQL', 'email' => 'recepcion@rutinas.test',
            'password' => 'ClaveSegura2026', 'role' => 'Recepcionista']);
        $this->reject(fn () => $call(60, 40, $reception->id), '42501');
        foreach ([[60, 60], [35.25, 64.75], [-1, 101], [null, 100]] as [$p, $o]) {
            $this->reject(fn () => $call($p, $o, $this->admin->id));
        }
        $before = AuditEvent::count();
        $call(60, 40, $this->admin->id);
        $quota = DoctorQuota::firstOrFail();
        $this->assertSame('60.00', $quota->presential_percent);
        $this->assertSame($before + 1, AuditEvent::count());
        $call(60, 40, $this->admin->id);
        $this->assertSame($before + 1, AuditEvent::count());
        $call(50, 50, $this->admin->id);
        $this->assertSame('50.00', $quota->fresh()->presential_percent);
        $this->assertDatabaseHas('audit_events', ['record_type' => 'DoctorQuota', 'record_id' => $quota->id, 'action' => 'updated', 'user_id' => $this->admin->id]);
        $this->doctor->update(['status' => 'Inactivo']);
        $this->reject(fn () => $call(70, 30, $this->admin->id));
        $this->assertSame('50.00', $quota->fresh()->presential_percent);
    }

    public function test_quota_procedure_preserves_holds_and_rolls_back_quota_with_its_audit(): void
    {
        $booking = $this->selection() + ['idempotency_key' => (string) Str::uuid(), 'modality' => 'online',
            'amount_minor' => 100, 'status' => 'held', 'expires_at' => now()->subHour(), 'deadline_at' => now()->subMinutes(30)];
        $hold = BookingRequest::create($booking);
        $call = fn () => DB::statement('CALL public.configurar_cupos_medico(?, 60, 40, ?)', [$this->doctor->id, $this->admin->id]);
        $this->reject($call);
        $this->assertSame('held', $hold->fresh()->status);
        $hold->update(['status' => 'expired']);
        $before = AuditEvent::count();
        try {
            DB::transaction(function () use ($call) {
                $call();
                throw new \RuntimeException('Revertir');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Revertir', $exception->getMessage());
        }
        $this->assertSame(0, DoctorQuota::count());
        $this->assertSame($before, AuditEvent::count());
    }
}
