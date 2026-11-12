<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$config = $connection->getConfig();
if ($config['driver'] !== 'pgsql' || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, 'Este respaldo solo lee la instalación PostgreSQL local.'.PHP_EOL);
    exit(1);
}
$binary = getenv('PG_DUMP_BINARY') ?: 'pg_dump';
$directory = storage_path('app/private/despliegue');
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('No se pudo crear el directorio privado de respaldo.');
}
$prefix = $directory.'/clinica-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
$transient = [
    'sessions', 'sesiones', 'cache', 'cache_datos', 'cache_locks', 'bloqueos_cache',
    'jobs', 'trabajos', 'job_batches', 'lotes_trabajos', 'failed_jobs', 'trabajos_fallidos',
    'password_reset_tokens', 'tokens_recuperacion_clave',
];
$pdo = $connection->getPdo();
$pdo->beginTransaction();
try {
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $snapshot = $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($tables as $table) {
        if (! in_array($table, $transient, true)) {
            $counts[$table] = (int) $pdo->query('SELECT count(*) FROM public."'.str_replace('"', '""', $table).'"')->fetchColumn();
        }
    }
    $arguments = [
        $binary, '--format=custom', '--no-owner', '--no-privileges', '--no-password',
        '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'],
        '--dbname=postgresql:///'.rawurlencode($config['database']), '--file='.$prefix.'.dump', '--snapshot='.$snapshot,
    ];
    foreach ($transient as $table) {
        $arguments[] = '--exclude-table-data=public.'.$table;
    }
    $process = new Process($arguments, base_path(), ['PGPASSWORD' => $config['password'] ?? '']);
    $process->setTimeout(300);
    $process->mustRun();
    $pdo->commit();
    $manifest = [
        'created_at' => date(DATE_ATOM), 'target_database' => 'clinica',
        'file' => basename($prefix.'.dump'), 'sha256' => hash_file('sha256', $prefix.'.dump'),
        'tables' => $counts, 'excluded_transient_data' => $transient,
    ];
    file_put_contents($prefix.'.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    fwrite(STDOUT, 'Respaldo privado: '.$prefix.'.dump'.PHP_EOL);
    fwrite(STDOUT, 'Manifiesto de integridad y cantidades: '.$prefix.'.json'.PHP_EOL);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
