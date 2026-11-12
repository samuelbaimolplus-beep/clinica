<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConfirmDemoEmailsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_known_fictitious_accounts_are_confirmed_without_emails_or_password_changes(): void
    {
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        $demo = User::create(['name' => 'Demo', 'username' => 'demo.admin', 'email' => 'demo.admin@example.test', 'role' => 'Administrador', 'status' => 'Activo', 'birth_date' => '1990-01-01', 'password' => 'ClaveOriginal2026']);
        $ordinary = User::create(['name' => 'Personal real', 'username' => 'personal.real', 'email' => 'personal@example.test', 'role' => 'Administrador', 'status' => 'Activo', 'birth_date' => '1990-01-01', 'password' => 'ClaveOriginal2026']);
        $hash = $demo->password;
        DB::table('users')->where('id', $demo->id)->update(['birth_date' => null]);
        DB::table('users')->where('id', $ordinary->id)->update(['birth_date' => null]);
        $this->artisan('clinic:confirm-demo-emails')->assertSuccessful();
        $this->assertNotNull($demo->fresh()->email_verified_at);
        $this->assertSame($hash, $demo->fresh()->password);
        $this->assertNull($ordinary->fresh()->email_verified_at);
        $this->assertSame('1990-01-01', $demo->fresh()->birth_date);
        $this->assertNull($ordinary->fresh()->birth_date);
        $stamp = $demo->fresh()->email_verified_at->toIso8601String();
        $this->artisan('clinic:confirm-demo-emails')->assertSuccessful();
        $this->assertSame($stamp, $demo->fresh()->email_verified_at->toIso8601String());
        Notification::assertNothingSent();
    }
}
