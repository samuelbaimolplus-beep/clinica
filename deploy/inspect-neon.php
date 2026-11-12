<?php

$url = parse_url(getenv('NEON_DATABASE_URL') ?: '');
parse_str($url['query'] ?? '', $options);
$host = $url['host'] ?? '';
$database = rawurldecode(ltrim($url['path'] ?? '', '/'));
if (! str_ends_with($host, '.neon.tech') || ! in_array($options['sslmode'] ?? '', ['require', 'verify-ca', 'verify-full'], true)) {
    fwrite(STDERR, 'Defina NEON_DATABASE_URL con la conexión TLS de Neon.'.PHP_EOL);
    exit(1);
}
try {
    if (isset($options['channel_binding'])) {
        putenv('PGCHANNELBINDING='.$options['channel_binding']);
    }
    $port = $url['port'] ?? 5432;
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname='$database';sslmode={$options['sslmode']};connect_timeout=10", rawurldecode($url['user'] ?? ''), rawurldecode($url['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $identity = $pdo->query('SELECT current_database() AS database, current_user AS role')->fetch(PDO::FETCH_ASSOC);
    $tables = $pdo->query("SELECT schemaname, tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY schemaname, tablename")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->commit();
    echo json_encode(['connected' => true, 'identity' => $identity, 'tls_mode' => $options['sslmode'], 'tables' => $tables], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudo comprobar la conexión: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
