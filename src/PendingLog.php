<?php

declare(strict_types=1);

namespace Company\AuditClient;

use BackedEnum;
use Company\AuditClient\Enums\Action;
use Company\AuditClient\Enums\Severity;
use Company\AuditClient\Security\Masker;
use Company\AuditClient\Support\EntityResolver;
use Company\AuditClient\Support\ValueNormalizer;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fluent builder for one log:
 *
 *   Audit::event('order.paid')
 *       ->by($user)->on($order)
 *       ->changes(['status' => 'pending'], ['status' => 'paid'])
 *       ->tags('billing')
 *       ->send();
 */
final class PendingLog
{
    /** Keys accepted by withOptions() / the $options argument of Audit::log(). */
    public const OPTIONS = ['action', 'severity', 'metadata', 'tags', 'external_id', 'occurred_at', 'correlation_id', 'request_id'];

    private const EVENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:\-\/]*$/';

    private const ACTION_PATTERN = '/^[a-z][a-z0-9_.\-]*$/';

    private ?string $action = null;

    private string $severity = 'info';

    private mixed $actor = null;

    private mixed $subject = null;

    /** @var array<array-key, mixed>|null */
    private ?array $oldValues = null;

    /** @var array<array-key, mixed>|null */
    private ?array $newValues = null;

    /** @var array<array-key, mixed>|null */
    private ?array $metadata = null;

    /** @var list<string> */
    private array $tags = [];

    private ?string $externalId = null;

    private DateTimeInterface|string|null $occurredAt = null;

    private ?string $correlationId = null;

    private ?string $requestId = null;

    public function __construct(private readonly AuditManager $manager, private readonly string $event) {}

    public function action(string|BackedEnum $action): self
    {
        $this->action = (string) ($action instanceof BackedEnum ? $action->value : $action);

        return $this;
    }

    public function severity(string|Severity $severity): self
    {
        $this->severity = $severity instanceof Severity ? $severity->value : $severity;

        return $this;
    }

    /**
     * Who did it: a model, an Authenticatable, an array with type/id/name/ip/user_agent,
     * or null for the authenticated user (if any).
     */
    public function by(mixed $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * What it was done to: a model or an array with type/id.
     */
    public function on(mixed $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|null  $old
     * @param  array<array-key, mixed>|null  $new
     */
    public function changes(?array $old, ?array $new): self
    {
        $this->oldValues = $old;
        $this->newValues = $new;

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|null  $metadata
     */
    public function metadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @param  string|list<string>  ...$tags
     */
    public function tags(string|array ...$tags): self
    {
        $flat = [];
        array_walk_recursive($tags, function (mixed $tag) use (&$flat): void {
            if (is_string($tag) && $tag !== '') {
                $flat[] = $tag;
            }
        });

        $this->tags = array_values(array_unique($flat));

        return $this;
    }

    /**
     * Idempotency key. Defaults to a ULID generated now, so queue retries
     * and outbox re-sends always reuse the same value.
     */
    public function externalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function occurredAt(DateTimeInterface|string $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function correlationId(?string $correlationId): self
    {
        $this->correlationId = $correlationId;

        return $this;
    }

    /**
     * Override the request id shared by the AddRequestId middleware.
     */
    public function requestId(?string $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * Apply an options array (action, severity, metadata, tags, external_id,
     * occurred_at, correlation_id, request_id). Unknown keys are a
     * programming error (Audit::log() reports it instead of throwing).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    public function withOptions(array $options): self
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown audit option(s): '.implode(', ', $unknown).'.');
        }

        $setters = [
            'action' => $this->action(...),
            'severity' => $this->severity(...),
            'metadata' => $this->metadata(...),
            'tags' => $this->tags(...),
            'external_id' => $this->externalId(...),
            'occurred_at' => $this->occurredAt(...),
            'correlation_id' => $this->correlationId(...),
            'request_id' => $this->requestId(...),
        ];

        foreach ($options as $key => $value) {
            if ($value !== null) {
                $setters[$key]($value);
            }
        }

        return $this;
    }

    /**
     * Record the log (buffered or queued). Returns its external_id, or null
     * when auditing is disabled or the log could not be recorded.
     */
    public function send(): ?string
    {
        return $this->manager->record($this);
    }

    /**
     * Validate, resolve, normalize and mask everything into the immutable
     * payload. Called by the manager inside its error guard, so an invalid
     * log is reported instead of breaking the caller.
     *
     * @throws InvalidArgumentException
     */
    public function toLogData(EntityResolver $entities, Masker $masker, ?string $requestId, bool $resolveActor): LogData
    {
        $action = $this->action ?? Action::fromEvent($this->event)->value;

        if (strlen($this->event) > 191 || preg_match(self::EVENT_PATTERN, $this->event) !== 1) {
            throw new InvalidArgumentException("Invalid audit event name [{$this->event}].");
        }

        if (strlen($action) > 32 || preg_match(self::ACTION_PATTERN, $action) !== 1) {
            throw new InvalidArgumentException("Invalid audit action [{$action}].");
        }

        if (Severity::tryFrom($this->severity) === null) {
            throw new InvalidArgumentException("Invalid audit severity [{$this->severity}].");
        }

        return new LogData(
            externalId: $this->externalId ?? (string) Str::ulid(),
            event: $this->event,
            action: $action,
            severity: $this->severity,
            occurredAt: ValueNormalizer::date($this->occurredAt ?? Carbon::now()),
            actor: $entities->actor($this->actor, $resolveActor),
            subject: $entities->subject($this->subject),
            oldValues: $masker->mask(ValueNormalizer::normalizeArray($this->oldValues)),
            newValues: $masker->mask(ValueNormalizer::normalizeArray($this->newValues)),
            metadata: $masker->mask(ValueNormalizer::normalizeArray($this->metadata)),
            tags: $this->tags,
            requestId: $this->requestId ?? $requestId,
            correlationId: $this->correlationId,
        );
    }
}
