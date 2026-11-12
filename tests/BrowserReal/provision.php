<?php
// Crea una base independiente para el navegador; nunca reinicia una base existente.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=config('database.connections.pgsql');
if($c['database']!=='clinica_vida_salud_browser_test')throw new RuntimeException('Base no autorizada para esta preparación.');
$pdo=new PDO('pgsql:host='.$c['host'].';port='.$c['port'].';dbname=postgres',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$pdo->prepare('select 1 from pg_database where datname=?');$q->execute([$c['database']]);
if(!$q->fetchColumn())$pdo->exec('CREATE DATABASE clinica_vida_salud_browser_test');
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
echo Illuminate\Support\Facades\Artisan::output();
