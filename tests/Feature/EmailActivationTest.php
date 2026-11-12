<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\VerifyClinicEmail;
use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\EmailActivation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailActivationTest extends TestCase
{
    use DatabaseTransactions;

    private function pending(string $role, string $suffix): User
    {
        $patient = $role === 'Paciente' ? Patient::create(['first_name' => 'Ana', 'last_name' => $suffix,
            'identity' => $suffix, 'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000000']) : null;
        $doctor = $role === 'Médico' ? Doctor::create(['registration' => $suffix, 'first_name' => 'Ana',
            'last_name' => $suffix, 'email' => 'medico.'.$suffix.'@activation.test', 'phone' => '70000000']) : null;

        return User::create(['birth_date' => '1990-01-01', 'terms_accepted_at' => now(), 'terms_version' => config('clinic.terms_version'), 'name' => $role, 'email' => $suffix.'@activation.test', 'username' => 'cuenta.'.$suffix,
            'password' => 'ClaveInicial2026', 'role' => $role, 'patient_id' => $patient?->id, 'doctor_id' => $doctor?->id]);
    }

    private function code(User $user): string
    {
        $codes = Notification::sent($user, VerifyClinicEmail::class);
        $this->assertNotEmpty($codes);

        return $codes->last()->code;
    }

    private function data(string $suffix): array
    {
        return ['first_name' => 'Ana', 'last_name' => 'Paciente '.$suffix, 'identity' => 'EMAIL-'.$suffix,
            'birth_date' => '2000-01-01', 'sex' => 'Femenino', 'phone' => '70000000',
            'email' => 'paciente.'.$suffix.'@activation.test', 'username' => 'paciente.'.$suffix,
            'password' => 'ClaveInicial2026', 'password_confirmation' => 'ClaveInicial2026', 'identity_verified' => true, 'terms_accepted' => true, 'terms_version' => config('clinic.terms_version')];
    }

    public function test_all_five_roles_are_blocked_before_activation_and_keep_their_permissions_afterwards(): void
    {
        foreach (['Superadmin', 'Administrador', 'Recepcionista', 'Médico', 'Paciente'] as $i => $role) {
            $user = $this->pending($role, 'rol'.$i);
            $login = ['email' => $user->email, 'password' => 'ClaveInicial2026'];
            $this->postJson('/api/login', $login)->assertForbidden()->assertJsonPath('activation_required', true)->assertJsonMissingPath('user');
            $this->assertGuest();
            $this->getJson('/api/session')->assertJsonPath('user', null)->assertJsonPath('access', []);
            $this->actingAs($user)->getJson('/api/references')->assertUnauthorized();
            $this->assertGuest();
            $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $this->code($user)])->assertOk();
            $this->assertGuest();
            $this->postJson('/api/login', $login)->assertOk()->assertJsonPath('user.role', $role);
            $this->getJson('/api/account/profile')->assertOk()->assertJsonPath('user.id', $user->id);
            if ($role === 'Paciente') {
                $this->getJson('/api/entities/users')->assertForbidden();
                $this->getJson('/api/entities/patients')->assertForbidden();
            }
            $this->postJson('/api/logout')->assertOk();
            $this->travel(61)->seconds();
        }
    }

    public function test_clinic_staff_create_patient_and_account_atomically_but_cannot_bypass_email_activation(): void
    {
        foreach (['Recepcionista', 'Administrador', 'Superadmin'] as $i => $role) {
            $actor = $this->verifiedAccount(['name' => $role, 'email' => 'staff'.$i.'@activation.test',
                'password' => 'ClaveInicial2026', 'role' => $role]);
            $data = $this->data('alta'.$i) + ['role' => 'Superadmin', 'email_verified_at' => now()->toISOString(), 'patient_id' => 999];
            $response = $this->actingAs($actor)->postJson('/api/patients/with-account', $data)->assertCreated()
                ->assertJsonPath('account.email_verified_at', null)->assertJsonPath('activation_email_sent', true);
            $user = User::findOrFail($response->json('account.id'));
            $this->assertSame('Paciente', $user->role);
            $this->assertNotNull($user->terms_accepted_at);
            $this->assertStringNotContainsString($this->code($user), $response->getContent());
            $this->assertStringNotContainsString('ClaveInicial2026', $response->getContent());
            $this->assertDatabaseHas('audit_events', ['action' => 'account_created_by_staff', 'user_id' => $actor->id]);
            $this->getJson('/api/entities/patients')->assertOk();
        }
        $patientUser = $this->pending('Paciente', 'sinpermiso');
        $patientUser->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($patientUser)->postJson('/api/patients/with-account', $this->data('prohibido'))->assertForbidden();
        $doctor = $this->pending('Médico', 'sinpermiso2');
        $doctor->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($doctor)->postJson('/api/patients/with-account', $this->data('prohibido2'))->assertForbidden();
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/patients/with-account', $this->data('anonimo'))->assertUnauthorized();
        $this->postJson('/api/register', $this->data('publico'))->assertNotFound();
    }

    public function test_creating_staff_accounts_also_sends_code_and_remains_pending(): void
    {
        $superadmin = $this->verifiedAccount(['name' => 'Superadmin', 'email' => 'super@activation.test', 'password' => 'ClaveInicial2026', 'role' => 'Superadmin']);
        $this->actingAs($superadmin);
        foreach (['Superadmin', 'Administrador', 'Recepcionista', 'Médico'] as $i => $role) {
            $doctor = $role === 'Médico' ? Doctor::create(['registration' => 'ALTA-MEDICO', 'first_name' => 'Ana', 'last_name' => 'Médica', 'email' => 'doctor.alta@activation.test', 'phone' => '70000000']) : null;
            $response = $this->postJson('/api/entities/users', ['birth_date' => '1990-01-01', 'name' => $role, 'email' => 'alta.staff'.$i.'@activation.test',
                'username' => 'personal'.$i, 'password' => 'ClaveInicial2026', 'role' => $role, 'status' => 'Activo',
                'doctor_id' => $doctor?->id, 'email_verified_at' => now()->toISOString()])->assertCreated()->assertJsonPath('email_verified_at', null);
            $user = User::findOrFail($response->json('id'));
            $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $this->code($user));
        }
    }

    public function test_expired_wrong_reused_and_over_attempt_codes_cannot_activate(): void
    {
        $user = $this->pending('Administrador', 'intentos');
        app(EmailActivation::class)->send($user);
        $code = $this->code($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => '00000000'])->assertUnprocessable();
        }
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->travel(16)->minutes();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        app(EmailActivation::class)->send($user);
        $newCode = $this->code($user);
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $newCode])->assertOk();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $newCode])->assertUnprocessable();
    }

    public function test_resending_revokes_previous_code_and_stores_only_hashes(): void
    {
        $user = $this->pending('Recepcionista', 'reenvio');
        app(EmailActivation::class)->send($user);
        $old = $this->code($user);
        $this->assertFalse(app(EmailActivation::class)->send($user));
        $this->travel(61)->seconds();
        $this->assertTrue(app(EmailActivation::class)->send($user));
        $new = $this->code($user);
        $this->assertNotSame($old, $new);
        $state = Cache::get('clinic:activation:'.$user->id);
        $this->assertStringNotContainsString($new, json_encode($state));
        $this->assertStringNotContainsString($new, AuditEvent::all()->toJson());
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $old])->assertUnprocessable();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $new])->assertOk();
    }

    public function test_unknown_inactive_and_other_email_do_not_activate_or_create_accounts(): void
    {
        $user = $this->pending('Administrador', 'inactiva');
        app(EmailActivation::class)->send($user);
        $code = $this->code($user);
        $before = User::count();
        $this->postJson('/api/account/activate', ['email' => 'nadie@activation.test', 'code' => $code])->assertUnprocessable();
        $user->update(['status' => 'Inactivo']);
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($before, User::count());
    }

    public function test_patient_can_manage_own_credentials_and_contact_after_activation_without_escalating_privileges(): void
    {
        $user = $this->pending('Paciente', 'perfil');
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'ClaveInicial2026'])->assertOk();
        $old = ['clinic' => session('clinic')];
        $this->patchJson('/api/account/password', ['current_password' => 'Incorrecta', 'password' => 'ClaveNueva2026', 'password_confirmation' => 'ClaveNueva2026'])->assertUnprocessable();
        $this->patchJson('/api/account/password', ['current_password' => 'ClaveInicial2026', 'password' => 'ClaveNueva2026', 'password_confirmation' => 'ClaveNueva2026'])->assertOk();
        $this->assertTrue(Hash::check('ClaveNueva2026', $user->fresh()->password));
        $this->getJson('/api/account/profile')->assertOk();
        $this->patchJson('/api/account/profile', ['email' => $user->email, 'phone' => '79999999', 'address' => 'Nueva dirección',
            'current_password' => 'ClaveNueva2026', 'role' => 'Superadmin', 'patient_id' => -1])->assertOk();
        $this->assertSame('79999999', $user->patient->fresh()->phone);
        $this->assertSame('Paciente', $user->fresh()->role);
        $this->actingAs($user->fresh())->withSession($old)->getJson('/api/account/profile')->assertUnauthorized();
    }

    public function test_changing_email_revokes_activation_and_requires_code_to_new_email(): void
    {
        $user = $this->pending('Paciente', 'cambiocorreo');
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'ClaveInicial2026'])->assertOk();
        $this->patchJson('/api/account/profile', ['email' => 'nuevo@activation.test', 'phone' => '70000000', 'address' => null,
            'current_password' => 'ClaveInicial2026'])->assertOk()->assertJsonPath('activation_required', true);
        $this->assertGuest();
        $user = $user->fresh();
        $this->assertNull($user->email_verified_at);
        $this->getJson('/api/references')->assertUnauthorized();
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $this->code($user)])->assertOk();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'ClaveInicial2026'])->assertOk();
    }

    public function test_codes_persist_in_postgresql_cache_without_storing_plaintext(): void
    {
        Cache::setDefaultDriver('database');
        $user = $this->pending('Administrador', 'persistente');
        $this->assertTrue(app(EmailActivation::class)->send($user));
        $code = $this->code($user);
        $state = Cache::get('clinic:activation:'.$user->id);
        $this->assertSame(64, strlen($state['hash']));
        $stored = DB::table('cache')->where('key', 'like', '%clinic:activation:%')->pluck('value')->implode('');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($code, $stored);
        $this->postJson('/api/account/activate', ['email' => $user->email, 'code' => $code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
