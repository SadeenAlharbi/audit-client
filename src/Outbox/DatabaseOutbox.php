<?php

declare(strict_types=1);

namespace Company\AuditClient\Outbox;

use Company\AuditClient\Http\ApiClient;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Outbox in a table of the host application (migration published with
 * --tag=audit-migrations). Uses the query builder only, no Eloquent model.
 */
final class DatabaseOutbox implements Outbox
{
    /** Fits a TEXT column even with 4-byte characters. */
    private const ERROR_LENGTH = 16000;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly string $table = 'audit_outbox',
        private readonly ?string $connection = null,
    ) {}

    public function store(array $payloads, string $error, int $attempts = 1): void
    {
        $now = Carbon::now();

        $rows = array_map(fn (array $payload): array => [
            'id' => (string) Str::ulid(),
            'external_id' => isset($payload['external_id']) && is_string($payload['external_id']) ? $payload['external_id'] : null,
            'payload' => ApiClient::encode($payload),
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, self::ERROR_LENGTH),
            'created_at' => $now,
            'updated_at' => $now,
        ], $payloads);

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->query()->insert($chunk);
        }
    }

    public function pending(int $limit, ?string $after = null): array
    {
        return $this->query()
            ->when($after !== null, fn (Builder $query) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): OutboxEntry => new OutboxEntry(
                id: (string) $row->id,
                payload: (array) json_decode((string) $row->payload, true),
                attempts: (int) $row->attempts,
                lastError: $row->last_error === null ? null : (string) $row->last_error,
                createdAt: (string) $row->created_at,
            ))
            ->values()
            ->all();
    }

    public function delete(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->query()->whereIn('id', $chunk)->delete();
        }
    }

    public function recordFailure(array $ids, string $error): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->query()->whereIn('id', $chunk)->update([
                'attempts' => $this->db->connection($this->connection)->raw('attempts + 1'),
                'last_error' => mb_substr($error, 0, self::ERROR_LENGTH),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query(): Builder
    {
        return $this->db->connection($this->connection)->table($this->table);
    }
}
