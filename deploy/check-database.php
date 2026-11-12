<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    DB::connection()->getPdo()->query('SELECT 1')->fetchColumn();
    fwrite(STDOUT, 'Conexión PostgreSQL comprobada con el usuario de los procesos de la aplicación.'.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falló la conexión PostgreSQL del usuario de la aplicación: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
