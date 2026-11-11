<?php

declare(strict_types=1);

namespace Company\AuditClient\Outbox;

/**
 * Local store for logs that could not be delivered. Each entry holds one
 * LogInput payload (already masked), how many delivery attempts were made
 * and the last error. "php artisan audit:flush" re-sends them.
 */
interface Outbox
{
    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public function store(array $payloads, string $error, int $attempts = 1): void;

    /**
     * The oldest entries, in insertion order, after the given entry id.
     *
     * @return list<OutboxEntry>
     */
    public function pending(int $limit, ?string $after = null): array;

    /**
     * Remove delivered entries.
     *
     * @param  list<string>  $ids
     */
    public function delete(array $ids): void;

    /**
     * Increment the attempts of entries that failed again and keep the error.
     *
     * @param  list<string>  $ids
     */
    public function recordFailure(array $ids, string $error): void;

    public function count(): int;
}
