<?php

declare(strict_types=1);

namespace Company\AuditClient;

use Company\AuditClient\Delivery\Deliverer;
use Company\AuditClient\Http\ApiClient;
use Company\AuditClient\Jobs\SendAuditLogs;
use Company\AuditClient\Outbox\Outbox;
use Company\AuditClient\Security\Masker;
use Company\AuditClient\Support\Buffer;
use Company\AuditClient\Support\EntityResolver;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Entry point behind the Audit facade. Builds logs, buffers them and hands
 * them to the queue. Recording a log never throws: problems are reported
 * to the exception handler and the application carries on.
 */
final class AuditManager
{
    /** Hidden Context key; Laravel carries it into jobs queued during the request. */
    public const REQUEST_ID_CONTEXT = 'audit-client.request_id';

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Dispatcher $bus,
        private readonly EntityResolver $entities,
        private readonly Masker $masker,
        private readonly Buffer $buffer,
    ) {}

    /**
     * Record one log. Returns its external_id, or null when disabled/failed.
     *
     * @param  array<array-key, mixed>|null  $old
     * @param  array<array-key, mixed>|null  $new
     * @param  array<string, mixed>  $options  action, severity, metadata, tags, external_id,
     *                                         occurred_at, correlation_id, request_id
     */
    public function log(string $event, mixed $actor = null, mixed $subject = null, ?array $old = null, ?array $new = null, array $options = []): ?string
    {
        return $this->guard(fn (): ?string => $this->event($event)
            ->by($actor)
            ->on($subject)
            ->changes($old, $new)
            ->withOptions($options)
            ->send());
    }

    /**
     * Start a fluent log: Audit::event('order.paid')->by($user)->on($order)->send().
     */
    public function event(string $event): PendingLog
    {
        return new PendingLog($this, $event);
    }

    /**
     * Buffer (batch mode) or queue a pending log.
     */
    public function record(PendingLog $log): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->guard(function () use ($log): string {
            $data = $log->toLogData(
                $this->entities,
                $this->masker,
                $this->requestId(),
                (bool) $this->config->get('audit-client.resolve_actor', true),
            );

            if (! $this->batching()) {
                $this->dispatch([$data->toArray()]);

                return $data->externalId;
            }

            $this->buffer->push($data);

            if ($this->buffer->count() >= $this->batchSize()) {
                $this->flush();
            }

            return $data->externalId;
        });
    }

    /**
     * Queue every buffered log, in chunks of batch.size (max 500).
     * Returns the number of logs queued.
     */
    public function flush(): int
    {
        $logs = $this->buffer->drain();

        foreach (array_chunk($logs, $this->batchSize()) as $chunk) {
            $this->dispatch(array_map(static fn (LogData $log): array => $log->toArray(), $chunk));
        }

        return count($logs);
    }

    public function buffered(): int
    {
        return $this->buffer->count();
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('audit-client.enabled', true);
    }

    /**
     * Share a request id with every log recorded from now on in this
     * request, and with jobs it queues (via hidden Context).
     */
    public function setRequestId(?string $requestId): void
    {
        $requestId === null
            ? Context::forgetHidden(self::REQUEST_ID_CONTEXT)
            : Context::addHidden(self::REQUEST_ID_CONTEXT, $requestId);
    }

    public function requestId(): ?string
    {
        $id = Context::getHidden(self::REQUEST_ID_CONTEXT);

        return is_string($id) ? $id : null;
    }

    /**
     * The low-level API client, for calls the facade does not wrap
     * (e.g. GET /batches/{uid}).
     */
    public function client(): ApiClient
    {
        return $this->container->make(ApiClient::class);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function dispatch(array $payloads): void
    {
        $job = new SendAuditLogs(
            $payloads,
            max(1, (int) $this->config->get('audit-client.retry.tries', 6)),
            array_values(array_map('intval', (array) $this->config->get('audit-client.retry.backoff', []))),
        );

        $job->onConnection($this->config->get('audit-client.queue.connection'))
            ->onQueue($this->config->get('audit-client.queue.name'));

        try {
            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            // The queue itself is unavailable: keep the logs locally.
            report($e);
            rescue(fn () => $this->container->make(Outbox::class)
                ->store($payloads, 'Could not be queued: '.$e->getMessage(), 0));
        }
    }

    private function batching(): bool
    {
        return (bool) $this->config->get('audit-client.batch.enabled', true);
    }

    private function batchSize(): int
    {
        return min(Deliverer::MAX_BATCH, max(1, (int) $this->config->get('audit-client.batch.size', 100)));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
