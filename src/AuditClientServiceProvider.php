<?php

declare(strict_types=1);

namespace Company\AuditClient;

use Company\AuditClient\Console\FlushCommand;
use Company\AuditClient\Http\ApiClient;
use Company\AuditClient\Http\Middleware\AddRequestId;
use Company\AuditClient\Listeners\LogAuthenticationEvents;
use Company\AuditClient\Outbox\DatabaseOutbox;
use Company\AuditClient\Outbox\FileOutbox;
use Company\AuditClient\Outbox\NullOutbox;
use Company\AuditClient\Outbox\Outbox;
use Company\AuditClient\Security\Masker;
use Company\AuditClient\Security\Signer;
use Company\AuditClient\Support\Buffer;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class AuditClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-client.php', 'audit-client');

        $this->app->singleton(Buffer::class);
        $this->app->singleton(AuditManager::class);

        $this->app->singleton(Masker::class, fn (Application $app): Masker => new Masker(
            (array) $this->config($app)->get('audit-client.mask', []),
            (string) $this->config($app)->get('audit-client.mask_replacement', '********'),
        ));

        // Resolved per use, so a queue worker always sends with the current config.
        $this->app->bind(ApiClient::class, function (Application $app): ApiClient {
            $config = $this->config($app);
            $secret = $config->get('audit-client.hmac_secret');

            return new ApiClient(
                endpoint: (string) $config->get('audit-client.endpoint'),
                project: (string) $config->get('audit-client.project'),
                apiKey: (string) $config->get('audit-client.api_key'),
                signer: is_string($secret) && $secret !== '' ? new Signer($secret) : null,
                timeout: (int) $config->get('audit-client.timeout', 5),
                connectTimeout: (int) $config->get('audit-client.connect_timeout', 2),
            );
        });

        $this->app->bind(Outbox::class, function (Application $app): Outbox {
            $config = $this->config($app);
            $driver = $config->get('audit-client.outbox.driver', 'database');

            return match ($driver) {
                'database' => new DatabaseOutbox(
                    $app->make('db'),
                    (string) $config->get('audit-client.outbox.table', 'audit_outbox'),
                    $config->get('audit-client.outbox.connection'),
                ),
                'file' => new FileOutbox((string) $config->get('audit-client.outbox.path')),
                'null', null => new NullOutbox($app->make('log')),
                default => throw new InvalidArgumentException("Unsupported audit outbox driver [{$driver}]."),
            };
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/audit-client.php' => $this->app->configPath('audit-client.php'),
        ], 'audit-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'audit-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class]);
        }

        $this->app->make(Router::class)->aliasMiddleware('audit.request-id', AddRequestId::class);

        $config = $this->config($this->app);
        $events = $this->app->make(Events::class);

        if ($config->get('audit-client.listen_auth_events', true)) {
            $events->subscribe(LogAuthenticationEvents::class);
        }

        if ($config->get('audit-client.batch.flush_on_terminate', true)) {
            $this->registerFlushPoints($events);
        }
    }

    /**
     * Send the buffer when a request / console command ends and after each
     * queued job of the host application.
     */
    private function registerFlushPoints(Events $events): void
    {
        $flush = fn (): int => $this->app->make(AuditManager::class)->flush();

        $this->app->terminating($flush);

        $events->listen([JobProcessed::class, JobExceptionOccurred::class], $flush);
    }

    private function config(Application $app): Config
    {
        return $app->make('config');
    }
}
