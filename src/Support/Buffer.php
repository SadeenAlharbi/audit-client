<?php

declare(strict_types=1);

namespace Company\AuditClient\Support;

use Company\AuditClient\LogData;

/**
 * In-memory collection of the logs recorded during the current request,
 * command or job, waiting to be sent as one batch.
 */
final class Buffer
{
    /** @var list<LogData> */
    private array $logs = [];

    public function push(LogData $log): void
    {
        $this->logs[] = $log;
    }

    public function count(): int
    {
        return count($this->logs);
    }

    public function isEmpty(): bool
    {
        return $this->logs === [];
    }

    /**
     * Remove and return every buffered log.
     *
     * @return list<LogData>
     */
    public function drain(): array
    {
        $logs = $this->logs;
        $this->logs = [];

        return $logs;
    }
}
