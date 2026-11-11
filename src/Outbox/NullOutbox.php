<?php

declare(strict_types=1);

namespace Company\AuditClient\Outbox;

use Psr\Log\LoggerInterface;

/**
 * Drops undeliverable logs. A warning (without the payload) is written to
 * the application log so the loss is visible.
 */
final class NullOutbox implements Outbox
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function store(array $payloads, string $error, int $attempts = 1): void
    {
        $this->logger->warning('Audit logs dropped (outbox driver "null").', [
            'count' => count($payloads),
            'external_ids' => array_column($payloads, 'external_id'),
            'error' => $error,
            'attempts' => $attempts,
        ]);
    }

    public function pending(int $limit, ?string $after = null): array
    {
        return [];
    }

    public function delete(array $ids): void {}

    public function recordFailure(array $ids, string $error): void {}

    public function count(): int
    {
        return 0;
    }
}
