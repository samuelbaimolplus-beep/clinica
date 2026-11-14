<?php

namespace Tests\Feature;

use App\Jobs\ConciliarPagoPayPal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeploymentPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_sessions_cache_and_queue_work_with_the_spanish_schema(): void
    {
        $this->assertSame('clinica_vida_salud_test', config('database.connections.pgsql.database'));
        config([
            'session.driver' => 'database', 'session.encrypt' => true,
            'cache.default' => 'database', 'queue.default' => 'database',
        ]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $this->getJson('/api/session')->assertOk()->assertJsonPath('user', null);
        $session = DB::table('sessions')->where('id', session()->getId())->first();
        $this->assertNotNull($session);
        $this->assertNotEmpty($session->payload);

        $key = 'render-persistence-'.Str::random(12);
        Cache::put($key, 'persisted', 60);
        $this->assertSame('persisted', Cache::get($key));
        Cache::forget($key);

        $id = Queue::push(new ConciliarPagoPayPal(0));
        $job = DB::table('jobs')->where('id', $id)->first();
        $this->assertNotNull($job);
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(ConciliarPagoPayPal::class, $payload['data']['commandName']);
    }
}
