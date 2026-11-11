<?php

declare(strict_types=1);

namespace Company\AuditClient\Delivery;

use Company\AuditClient\Exceptions\TransientDeliveryException;
use Company\AuditClient\Http\ApiClient;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Sends prepared LogInput payloads and classifies the answer:
 *
 *  - 202 / 409 duplicate        -> success (DeliveryReport)
 *  - 202 batch item "rejected"  -> that item is rejected (DeliveryReport)
 *  - other 4xx                  -> every log rejected (DeliveryReport)
 *  - network error, 408, 429, 5xx -> TransientDeliveryException
 *
 * One payload is sent with POST /logs, several with POST /logs/batch.
 */
final class Deliverer
{
    /** Maximum number of logs per POST /logs/batch request. */
    public const MAX_BATCH = 500;

    public function __construct(private readonly ApiClient $client) {}

    /**
     * @param  list<array<string, mixed>>  $logs  LogInput payloads (at most 500)
     *
     * @throws TransientDeliveryException
     * @throws \JsonException
     */
    public function deliver(array $logs): DeliveryReport
    {
        $count = count($logs);

        if ($count === 0) {
            return new DeliveryReport;
        }

        if ($count > self::MAX_BATCH) {
            throw new \InvalidArgumentException('At most '.self::MAX_BATCH.' logs can be delivered in one request.');
        }

        try {
            $response = $count === 1
                ? $this->client->send('POST', 'logs', payload: $logs[0])
                : $this->client->send('POST', 'logs/batch', payload: ['logs' => $logs]);
        } catch (ConnectionException $e) {
            throw new TransientDeliveryException('Audit platform unreachable: '.$e->getMessage(), previous: $e);
        }

        return $this->interpret($response, $count);
    }

    private function interpret(Response $response, int $count): DeliveryReport
    {
        $status = $response->status();

        if ($status === 409 && $response->json('error.code') === 'duplicate') {
            return new DeliveryReport(duplicates: $count);
        }

        if ($status === 408 || $status === 429 || $status >= 500) {
            throw new TransientDeliveryException(self::describe($response), $this->retryAfter($response));
        }

        if ($response->successful()) {
            return $count === 1 ? new DeliveryReport(accepted: 1) : $this->batchReport($response, $count);
        }

        return $this->rejectAll($response, $count);
    }

    /**
     * 202 from POST /logs/batch: every item is reported by its index.
     */
    private function batchReport(Response $response, int $count): DeliveryReport
    {
        $rejected = [];
        $items = $response->json('data.items');

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && ($item['status'] ?? null) === 'rejected' && is_int($item['index'] ?? null)) {
                $rejected[$item['index']] = 'Rejected by the platform: '.self::json($item['errors'] ?? []);
            }
        }

        $duplicates = (int) $response->json('data.duplicates', 0);

        return new DeliveryReport(
            accepted: (int) $response->json('data.accepted', $count - $duplicates - count($rejected)),
            duplicates: $duplicates,
            rejected: $rejected,
        );
    }

    /**
     * A 4xx that retrying cannot fix (validation, unauthorized, forbidden,
     * payload_too_large, ...). For a batch 422 the per-item errors in
     * details.items are kept when present.
     */
    private function rejectAll(Response $response, int $count): DeliveryReport
    {
        $message = self::describe($response);
        $rejected = array_fill(0, $count, $message);

        $items = $response->json('error.details.items');
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_int($item['index'] ?? null) && $item['index'] < $count) {
                $rejected[$item['index']] = 'HTTP '.$response->status().' validation: '.self::json($item['errors'] ?? []);
            }
        }

        return new DeliveryReport(rejected: $rejected);
    }

    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return max(1, (int) $header);
        }

        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);

        return $date === false ? null : max(1, $date->getTimestamp() - time());
    }

    private static function describe(Response $response): string
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');
        $details = $response->json('error.details');

        $text = 'HTTP '.$response->status();
        $text .= is_string($code) ? ' '.$code : '';
        $text .= is_string($message) ? ': '.$message : '';
        $text .= is_array($details) && $details !== [] ? ' '.self::json($details) : '';

        if (! is_string($code) && ! is_string($message)) {
            $text .= ': '.mb_substr(trim($response->body()), 0, 500);
        }

        return $text;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
