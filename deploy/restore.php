<?php

use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$manifestPath = $argv[1] ?? '';
if (! is_file($manifestPath)) {
    fwrite(STDERR, 'Uso: php deploy/restore.php RUTA_MANIFIESTO.json. Defina NEON_DATABASE_URL como secreto en el entorno.'.PHP_EOL);
    exit(1);
}
$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$dump = dirname($manifestPath).'/'.basename($manifest['file']);
if (! is_file($dump) || ! hash_equals($manifest['sha256'], hash_file('sha256', $dump))) {
    throw new RuntimeException('El respaldo no coincide con su manifiesto de integridad.');
}
$url = parse_url(getenv('NEON_DATABASE_URL') ?: '');
parse_str($url['query'] ?? '', $options);
$database = rawurldecode(ltrim($url['path'] ?? '', '/'));
$host = $url['host'] ?? '';
$sslmode = $options['sslmode'] ?? '';
$expectedDatabase = getenv('CLINIC_DEPLOY_DATABASE') ?: 'clinica';
if (! in_array($url['scheme'] ?? '', ['postgres', 'postgresql'], true)
    || ! str_ends_with($host, '.neon.tech') || str_contains($host, '-pooler')
    || ! preg_match('/^[a-zA-Z0-9_]+$/', $expectedDatabase)
    || in_array(strtolower($expectedDatabase), ['vetproy', 'petdiatria'], true)
    || $database !== $expectedDatabase || ($manifest['target_database'] ?? '') !== 'clinica'
    || ! in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true)) {
    throw new RuntimeException('Use la conexión directa TLS de Neon a la base independiente CLINIC_DEPLOY_DATABASE. No se admite otra base ni el pooler.');
}
$port = $url['port'] ?? 5432;
$username = rawurldecode($url['user'] ?? '');
$password = rawurldecode($url['pass'] ?? '');
putenv('PGCHANNELBINDING='.($options['channel_binding'] ?? 'prefer'));
$pdo = new PDO("pgsql:host=$host;port=$port;dbname=$database;sslmode=$sslmode;connect_timeout=10", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = (int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn();
if ($tables !== 0) {
    throw new RuntimeException('La base destino ya contiene tablas. No se restauró ni se eliminó ningún dato.');
}
$process = new Process([
    getenv('PG_RESTORE_BINARY') ?: 'pg_restore', '--single-transaction', '--exit-on-error',
    '--no-owner', '--no-privileges', '--no-password', '--host='.$host, '--port='.$port,
    '--username='.$username, '--dbname='.$database, $dump,
], dirname(__DIR__), [
    'PGPASSWORD' => $password, 'PGSSLMODE' => $sslmode,
    'PGCHANNELBINDING' => $options['channel_binding'] ?? 'prefer',
]);
$process->setTimeout(600);
$process->mustRun();
foreach ($manifest['tables'] as $table => $expected) {
    $actual = (int) $pdo->query('SELECT count(*) FROM public."'.str_replace('"', '""', $table).'"')->fetchColumn();
    if ($actual !== $expected) {
        throw new RuntimeException('La cantidad de filas restauradas no coincide para '.$table.'. Revise antes de desplegar.');
    }
}
fwrite(STDOUT, 'Restauración completada en '.$database.'; cantidades verificadas contra el respaldo. La base local no se modificó.'.PHP_EOL);
