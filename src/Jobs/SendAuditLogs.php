<?php

declare(strict_types=1);

namespace Company\AuditClient\Jobs;

use Company\AuditClient\Delivery\Deliverer;
use Company\AuditClient\Exceptions\TransientDeliveryException;
use Company\AuditClient\Outbox\Outbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Delivers up to 500 prepared logs to the platform.
 *
 * - Success and 409 duplicate: done.
 * - Rejected logs (4xx, per-item batch rejections): written to the outbox
 *   with the error, never retried.
 * - Network errors, 408, 429 and 5xx: released back onto the queue with
 *   exponential backoff (Retry-After wins); after the last try, or on the
 *   sync connection, the logs are written to the outbox.
 *
 * The job never throws for a delivery problem, so a platform outage can
 * neither fail the host's queue nor, on the sync connection, the request.
 */
final class SendAuditLogs implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $logs  LogInput payloads, already masked
     * @param  int  $tries  total delivery attempts
     * @param  list<int>  $delays  seconds before each retry
     */
    public function __construct(
        public readonly array $logs,
        public int $tries = 6,
        private readonly array $delays = [10, 30, 90, 270, 810],
    ) {}

    public function handle(Container $container, Outbox $outbox): void
    {
        try {
            $report = $container->make(Deliverer::class)->deliver($this->logs);
        } catch (TransientDeliveryException $e) {
            if ($this->canRetry()) {
                $this->release($e->retryAfter ?? $this->delayForAttempt($this->attempts()));

                return;
            }

            $outbox->store($this->logs, $e->getMessage(), $this->attempts());

            return;
        } catch (Throwable $e) {
            report($e);
            $outbox->store($this->logs, 'Unexpected error: '.$e->getMessage(), $this->attempts());

            return;
        }

        foreach ($report->rejectedByError() as $error => $indexes) {
            $outbox->store(array_values(array_intersect_key($this->logs, array_flip($indexes))), $error, $this->attempts());
        }
    }

    /**
     * Used by the worker if the job dies unexpectedly (e.g. timeout).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->delays;
    }

    /**
     * Last resort when the worker gives up on the job: keep the logs.
     */
    public function failed(?Throwable $exception = null): void
    {
        rescue(fn () => app(Outbox::class)->store(
            $this->logs,
            'Job failed: '.($exception?->getMessage() ?? 'unknown error'),
            $this->attempts(),
        ));
    }

    /**
     * Seconds to wait after the given (1-based) attempt failed.
     */
    public function delayForAttempt(int $attempt): int
    {
        if ($this->delays === []) {
            return 0;
        }

        return $this->delays[min(max($attempt, 1), count($this->delays)) - 1];
    }

    private function canRetry(): bool
    {
        return $this->job !== null
            && ! $this->job instanceof SyncJob
            && $this->attempts() < $this->tries;
    }
}
