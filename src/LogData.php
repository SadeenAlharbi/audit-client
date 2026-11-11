<?php

declare(strict_types=1);

namespace Company\AuditClient;

/**
 * One audit log, ready to be sent: normalized, masked and immutable.
 *
 * toArray() returns exactly the OpenAPI "LogInput" shape. Optional fields
 * that are null or empty are omitted, so the platform never receives a key
 * it does not know.
 */
final class LogData
{
    /** Allowed keys of LogInput.actor. */
    public const ACTOR_KEYS = ['type', 'id', 'name', 'ip', 'user_agent'];

    /** Allowed keys of LogInput.subject. */
    public const SUBJECT_KEYS = ['type', 'id'];

    /**
     * @param  array<string, string>|null  $actor
     * @param  array<string, string>|null  $subject
     * @param  array<array-key, mixed>|null  $oldValues
     * @param  array<array-key, mixed>|null  $newValues
     * @param  array<array-key, mixed>|null  $metadata
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $event,
        public readonly string $action,
        public readonly string $severity,
        public readonly string $occurredAt,
        public readonly ?array $actor = null,
        public readonly ?array $subject = null,
        public readonly ?array $oldValues = null,
        public readonly ?array $newValues = null,
        public readonly ?array $metadata = null,
        public readonly array $tags = [],
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'external_id' => $this->externalId,
            'event' => $this->event,
            'action' => $this->action,
            'severity' => $this->severity,
            'occurred_at' => $this->occurredAt,
            'actor' => $this->actor,
            'subject' => $this->subject,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'metadata' => $this->metadata,
            'tags' => $this->tags,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }
}
