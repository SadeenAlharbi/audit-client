<?php

declare(strict_types=1);

namespace Company\AuditClient\Outbox;

final class OutboxEntry
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly ?string $lastError,
        public readonly string $createdAt,
    ) {}
}
