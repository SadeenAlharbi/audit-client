<?php

declare(strict_types=1);

namespace Company\AuditClient\Outbox;

use Company\AuditClient\Http\ApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Outbox in a JSON-lines file, one entry per line:
 *
 *   {"id":"01J...","payload":{...},"attempts":6,"last_error":"...","created_at":"..."}
 *
 * Every read and write holds an exclusive lock on the file, so web workers,
 * queue workers and audit:flush can use it concurrently.
 */
final class FileOutbox implements Outbox
{
    public function __construct(private readonly string $path) {}

    public function store(array $payloads, string $error, int $attempts = 1): void
    {
        $now = Carbon::now()->toIso8601String();
        $lines = '';

        foreach ($payloads as $payload) {
            $lines .= ApiClient::encode([
                'id' => (string) Str::ulid(),
                'payload' => $payload,
                'attempts' => $attempts,
                'last_error' => $error,
                'created_at' => $now,
            ])."\n";
        }

        $this->locked(function ($handle) use ($lines): void {
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $lines);
        });
    }

    public function pending(int $limit, ?string $after = null): array
    {
        $entries = array_filter(
            $this->locked(fn ($handle): array => $this->read($handle)),
            fn (OutboxEntry $entry): bool => $after === null || strcmp($entry->id, $after) > 0,
        );

        usort($entries, fn (OutboxEntry $a, OutboxEntry $b): int => strcmp($a->id, $b->id));

        return array_slice($entries, 0, $limit);
    }

    public function delete(array $ids): void
    {
        $remove = array_flip($ids);

        $this->rewrite(fn (OutboxEntry $entry): ?OutboxEntry => isset($remove[$entry->id]) ? null : $entry);
    }

    public function recordFailure(array $ids, string $error): void
    {
        $failed = array_flip($ids);

        $this->rewrite(fn (OutboxEntry $entry): OutboxEntry => isset($failed[$entry->id])
            ? new OutboxEntry($entry->id, $entry->payload, $entry->attempts + 1, $error, $entry->createdAt)
            : $entry);
    }

    public function count(): int
    {
        return count($this->locked(fn ($handle): array => $this->read($handle)));
    }

    /**
     * @param  callable(OutboxEntry): ?OutboxEntry  $map  null removes the entry
     */
    private function rewrite(callable $map): void
    {
        $this->locked(function ($handle) use ($map): void {
            $lines = '';
            foreach ($this->read($handle) as $entry) {
                $entry = $map($entry);
                if ($entry !== null) {
                    $lines .= ApiClient::encode([
                        'id' => $entry->id,
                        'payload' => $entry->payload,
                        'attempts' => $entry->attempts,
                        'last_error' => $entry->lastError,
                        'created_at' => $entry->createdAt,
                    ])."\n";
                }
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $lines);
        });
    }

    /**
     * @param  resource  $handle
     * @return list<OutboxEntry>
     */
    private function read($handle): array
    {
        rewind($handle);
        $entries = [];

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);

            if (is_array($row) && isset($row['id'], $row['payload']) && is_array($row['payload'])) {
                $entries[] = new OutboxEntry(
                    id: (string) $row['id'],
                    payload: $row['payload'],
                    attempts: (int) ($row['attempts'] ?? 0),
                    lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
                    createdAt: (string) ($row['created_at'] ?? ''),
                );
            }
        }

        return $entries;
    }

    /**
     * Run $callback with an exclusive lock on the outbox file.
     *
     * @template T
     *
     * @param  callable(resource): T  $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the audit outbox directory [{$directory}].");
        }

        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the audit outbox file [{$this->path}].");
        }

        try {
            flock($handle, LOCK_EX);

            return $callback($handle);
        } finally {
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
