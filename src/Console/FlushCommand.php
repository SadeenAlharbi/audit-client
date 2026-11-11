<?php

declare(strict_types=1);

namespace Company\AuditClient\Console;

use Company\AuditClient\Delivery\Deliverer;
use Company\AuditClient\Exceptions\TransientDeliveryException;
use Company\AuditClient\Outbox\Outbox;
use Company\AuditClient\Outbox\OutboxEntry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Re-sends the logs kept in the outbox, oldest first, in batches.
 * Delivered (or duplicate) entries are deleted; entries that fail again
 * stay, with their attempts incremented and the new error. Stops early if
 * the platform is unavailable.
 */
#[AsCommand(name: 'audit:flush')]
final class FlushCommand extends Command
{
    /** @var string */
    protected $signature = 'audit:flush
        {--batch=500 : Logs per request (1-500)}
        {--limit=0 : Maximum number of entries to process (0 = all)}';

    /** @var string */
    protected $description = 'Re-send audit logs stored in the local outbox';

    public function handle(Outbox $outbox, Deliverer $deliverer): int
    {
        $size = min(Deliverer::MAX_BATCH, max(1, (int) $this->option('batch')));
        $limit = max(0, (int) $this->option('limit'));
        $sent = 0;
        $failed = 0;
        $after = null;

        while ($limit === 0 || $sent + $failed < $limit) {
            $take = $limit === 0 ? $size : min($size, $limit - $sent - $failed);
            $entries = $outbox->pending($take, $after);

            if ($entries === []) {
                break;
            }

            $after = $entries[array_key_last($entries)]->id;
            $ids = array_map(static fn (OutboxEntry $entry): string => $entry->id, $entries);

            try {
                $report = $deliverer->deliver(array_map(static fn (OutboxEntry $entry): array => $entry->payload, $entries));
            } catch (TransientDeliveryException $e) {
                $outbox->recordFailure($ids, $e->getMessage());
                $this->components->error('The audit platform is unavailable: '.$e->getMessage());
                $this->summary($sent, $failed + count($ids), $outbox);

                return self::FAILURE;
            } catch (Throwable $e) {
                $outbox->recordFailure($ids, 'Unexpected error: '.$e->getMessage());
                $failed += count($ids);

                continue;
            }

            $rejected = [];
            foreach ($report->rejectedByError() as $error => $indexes) {
                $failedIds = array_map(static fn (int $index): string => $ids[$index], $indexes);
                $outbox->recordFailure($failedIds, $error);
                $rejected = array_merge($rejected, $failedIds);
            }

            $delivered = array_values(array_diff($ids, $rejected));
            $outbox->delete($delivered);

            $sent += count($delivered);
            $failed += count($rejected);
        }

        $this->summary($sent, $failed, $outbox);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function summary(int $sent, int $failed, Outbox $outbox): void
    {
        $this->components->info(sprintf(
            'Audit outbox: %d sent, %d failed, %d remaining.',
            $sent,
            $failed,
            $outbox->count(),
        ));
    }
}
