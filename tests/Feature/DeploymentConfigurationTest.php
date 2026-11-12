<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    private function validateDeployment(array $overrides = []): Process
    {
        $process = new Process([PHP_BINARY, 'deploy/validate.php'], base_path(), array_merge([
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'APP_URL' => 'https://clinica-example.onrender.com',
            'DB_URL' => false,
            'DATABASE_URL' => 'postgresql://test:test@unreachable.neon.tech/clinica?sslmode=require',
            'DB_CONNECTION' => 'pgsql', 'DB_SSLMODE' => 'require',
            'CLINIC_DEPLOY_DATABASE' => 'clinica',
            'SESSION_DRIVER' => 'database', 'SESSION_SECURE_COOKIE' => 'true',
            'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
        ], $overrides));
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    public function test_neon_url_is_accepted_without_connecting_to_or_migrating_the_database(): void
    {
        $process = $this->validateDeployment();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_neon_default_database_is_accepted_when_explicitly_selected(): void
    {
        $process = $this->validateDeployment([
            'DATABASE_URL' => 'postgresql://test:test@unreachable.neon.tech/neondb?sslmode=require&channel_binding=require',
            'CLINIC_DEPLOY_DATABASE' => 'neondb',
        ]);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_startup_refuses_the_vetproy_database_even_if_selected_as_expected(): void
    {
        $process = $this->validateDeployment([
            'DATABASE_URL' => 'postgresql://test:test@unreachable.neon.tech/vetproy?sslmode=require',
            'CLINIC_DEPLOY_DATABASE' => 'vetproy',
        ]);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('nunca a vetproy', $process->getErrorOutput());
    }

    public function test_startup_refuses_a_different_database_or_unencrypted_transport(): void
    {
        $process = $this->validateDeployment([
            'DATABASE_URL' => 'postgresql://test:test@unreachable.neon.tech/other?sslmode=disable',
        ]);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('base independiente', $process->getErrorOutput());
        $this->assertStringContainsString('requiere DB_SSLMODE', $process->getErrorOutput());
    }

    public function test_startup_refuses_debug_missing_key_http_and_ephemeral_sessions(): void
    {
        $process = $this->validateDeployment([
            'APP_DEBUG' => 'true', 'APP_KEY' => '', 'APP_URL' => 'http://example.test',
            'SESSION_DRIVER' => 'file',
        ]);
        $this->assertSame(1, $process->getExitCode());
        foreach (['APP_DEBUG=false', 'APP_KEY', 'HTTPS', 'SESSION_DRIVER'] as $message) {
            $this->assertStringContainsString($message, $process->getErrorOutput());
        }
    }
}
