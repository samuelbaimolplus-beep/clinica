<?php

/** Prepara el esquema de la base exclusiva de pruebas, con comprobación del nombre antes de migrar. */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepararPruebasClinica extends Command
{
    protected $signature = 'clinica:preparar-pruebas';

    protected $description = 'Aplica migraciones exclusivamente en clinica_vida_salud_test, sin borrar datos.';

    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(): int
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => null, 'database.connections.pgsql.database' => 'clinica_vida_salud_test']);
        DB::purge('pgsql');
        $baseDatos = DB::selectOne('SELECT current_database() AS name')->name;
        if ($baseDatos !== 'clinica_vida_salud_test') {
            $this->error('Se rechazó la operación: la conexión no apunta a la base de pruebas.');

            return self::FAILURE;
        }

        return $this->call('migrate', ['--force' => true]);
    }
}
