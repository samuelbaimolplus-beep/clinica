<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$errors = [];
if (! $app->environment('production') || config('app.debug')) {
    $errors[] = 'Configure APP_ENV=production y APP_DEBUG=false.';
}
try {
    $app->make('encrypter');
} catch (Throwable) {
    $errors[] = 'Configure una APP_KEY válida y permanente, generada con artisan key:generate --show.';
}
if (parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
    $errors[] = 'APP_URL o RENDER_EXTERNAL_URL debe ser la URL HTTPS del nuevo servicio.';
}
$connection = DB::connection();
$expectedDatabase = getenv('CLINIC_DEPLOY_DATABASE') ?: 'clinica';
if ($connection->getDriverName() !== 'pgsql'
    || $connection->getConfig('database') !== $expectedDatabase
    || in_array(mb_strtolower($expectedDatabase), ['vetproy', 'petdiatria'], true)) {
    $errors[] = 'La conexión debe apuntar a la base independiente CLINIC_DEPLOY_DATABASE (clinica por defecto), nunca a vetproy.';
}
if (! in_array($connection->getConfig('sslmode'), ['require', 'verify-ca', 'verify-full'], true)) {
    $errors[] = 'La conexión a Neon requiere DB_SSLMODE=require o verificación TLS superior.';
}
if (config('session.driver') !== 'database' || config('cache.default') !== 'database'
    || config('queue.default') !== 'database' || ! config('session.secure')) {
    $errors[] = 'Use SESSION_DRIVER, CACHE_STORE y QUEUE_CONNECTION=database y SESSION_SECURE_COOKIE=true.';
}
if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error.PHP_EOL);
    }
    exit(1);
}
fwrite(STDOUT, 'Configuración de despliegue validada; se aplicarán las migraciones a la base independiente.'.PHP_EOL);
