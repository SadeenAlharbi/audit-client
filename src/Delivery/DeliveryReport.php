<?php

declare(strict_types=1);

namespace Company\AuditClient\Delivery;

/**
 * Final outcome of one delivery. Accepted and duplicate logs are both
 * successes (the platform has them); rejected logs will never be accepted
 * as they are and carry the reason, keyed by their index in the request.
 */
final class DeliveryReport
{
    /**
     * @param  array<int, string>  $rejected  index => error message
     */
    public function __construct(
        public readonly int $accepted = 0,
        public readonly int $duplicates = 0,
        public readonly array $rejected = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->rejected === [];
    }

    /**
     * Rejected indexes grouped by error message, so callers can store all
     * logs that failed for the same reason in one go.
     *
     * @return array<string, list<int>>
     */
    public function rejectedByError(): array
    {
        $groups = [];
        foreach ($this->rejected as $index => $error) {
            $groups[$error][] = $index;
        }

        return $groups;
    }
}
