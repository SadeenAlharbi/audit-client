<?php

declare(strict_types=1);

namespace Company\AuditClient\Tests;

use Company\AuditClient\AuditClientServiceProvider;
use Company\AuditClient\Facades\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const ENDPOINT = 'https://audit.test/api/v1';

    public const SECRET = 'test-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [AuditClientServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Audit' => Audit::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');

        $app['config']->set('audit-client.endpoint', self::ENDPOINT);
        $app['config']->set('audit-client.project', 'shop');
        $app['config']->set('audit-client.api_key', 'alh_test_key');
        $app['config']->set('audit-client.hmac_secret', self::SECRET);
        $app['config']->set('audit-client.queue.connection', 'sync');
        $app['config']->set('audit-client.batch.enabled', false);
        $app['config']->set('audit-client.outbox.driver', 'database');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
