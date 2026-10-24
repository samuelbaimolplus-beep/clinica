<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareClinicTests extends Command
{
    protected $signature = 'clinica:preparar-pruebas';

    protected $description = 'Aplica migraciones exclusivamente en clinica_vida_salud_test, sin borrar datos.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => null, 'database.connections.pgsql.database' => 'clinica_vida_salud_test']);
        DB::purge('pgsql');
        $database = DB::selectOne('SELECT current_database() AS name')->name;
        if ($database !== 'clinica_vida_salud_test') {
            $this->error('Se rechazó la operación: la conexión no apunta a la base de pruebas.');

            return self::FAILURE;
        }

        return $this->call('migrate', ['--force' => true]);
    }
}
