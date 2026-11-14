<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_demo_data_is_repeatable_preserves_accounts_and_keeps_passwords_private(): void
    {
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $this->travelTo(Carbon::parse('2026-10-24 13:00:00', 'America/La_Paz'));
        Storage::fake('local');
        $admin = $this->verifiedAccount(['name' => 'Administrador existente', 'email' => 'admin@existing.example', 'password' => 'ExistingPassword2026', 'role' => 'Administrador', 'status' => 'Activo']);
        $password = $admin->password;

        $this->seed(DemoDataSeeder::class);
        $this->assertDatabaseCount('specialties', 4);
        $this->assertDatabaseCount('doctors', 5);
        $this->assertDatabaseCount('patients', 10);
        $this->assertDatabaseCount('schedules', 30);
        $this->assertDatabaseCount('appointments', 16);
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('unavailabilities', 1);
        foreach (array_diff(Cita::STATES, ['Confirmada']) as $state) {
            $this->assertTrue(Cita::where('status', $state)->exists());
        }
        Storage::disk('local')->assertExists('accesos-demo.txt');
        $privateFile = Storage::disk('local')->get('accesos-demo.txt');
        $receptionPassword = Usuario::where('email', 'recepcion@demo.example')->firstOrFail()->password;

        $this->seed(DemoDataSeeder::class);
        $this->assertSame(4, Especialidad::count());
        $this->assertSame(5, Medico::count());
        $this->assertSame(10, Paciente::count());
        $this->assertSame(16, Cita::count());
        $this->assertSame(7, Usuario::count());
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertSame($receptionPassword, Usuario::where('email', 'recepcion@demo.example')->firstOrFail()->password);
        $this->assertSame($privateFile, Storage::disk('local')->get('accesos-demo.txt'));
    }
}
