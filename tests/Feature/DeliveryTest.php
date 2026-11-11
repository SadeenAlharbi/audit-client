<?php

declare(strict_types=1);

use Company\AuditClient\Facades\Audit;
use Company\AuditClient\Jobs\SendAuditLogs;
use Company\AuditClient\Outbox\Outbox;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

function payload(string $externalId = 'ext-1'): array
{
    return [
        'external_id' => $externalId,
        'event' => 'order.paid',
        'action' => 'update',
        'severity' => 'info',
        'occurred_at' => '2026-11-11T10:00:00.000Z',
    ];
}

/**
 * Run the job as the worker would on its N-th attempt; returns the spy
 * standing in for the queue job, to assert releases on.
 */
function runJob(SendAuditLogs $job, int $attempt = 1): MockInterface
{
    $queueJob = Mockery::spy(QueueJob::class);
    $queueJob->allows('attempts')->andReturn($attempt);

    $job->setJob($queueJob);
    $job->handle(app(), app(Outbox::class));

    return $queueJob;
}

it('treats 409 duplicate as success', function () {
    Http::fake(['audit.test/*' => Http::response(['error' => [
        'code' => 'duplicate',
        'message' => 'A log with this external_id was already received.',
        'details' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'external_id' => 'ext-1'],
    ]], 409)]);

    runJob(new SendAuditLogs([payload()]))->shouldNotHaveReceived('release');

    expect(DB::table('audit_outbox')->count())->toBe(0);
});

it('writes 422 validation errors to the outbox without retrying', function () {
    Http::fake(['audit.test/*' => Http::response(['error' => [
        'code' => 'validation',
        'message' => 'The given data was invalid.',
        'details' => ['occurred_at' => ['The occurred_at is older than the retention period.']],
    ]], 422)]);

    runJob(new SendAuditLogs([payload()]))->shouldNotHaveReceived('release');
    Http::assertSentCount(1);

    $row = DB::table('audit_outbox')->sole();
    expect($row->external_id)->toBe('ext-1')
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('HTTP 422 validation')->toContain('older than the retention period')
        ->and(json_decode($row->payload, true))->toBe(payload());
});

it('retries 5xx with exponential backoff and moves the logs to the outbox after the last try', function () {
    Http::fake(['audit.test/*' => Http::response(['error' => ['code' => 'server_error', 'message' => 'Boom']], 503)]);

    foreach ([1 => 10, 2 => 30, 3 => 90, 4 => 270, 5 => 810] as $attempt => $delay) {
        runJob(new SendAuditLogs([payload()]), $attempt)->shouldHaveReceived('release')->once()->with($delay);
    }
    expect(DB::table('audit_outbox')->count())->toBe(0);

    runJob(new SendAuditLogs([payload()]), 6)->shouldNotHaveReceived('release');
    $row = DB::table('audit_outbox')->sole();
    expect((int) $row->attempts)->toBe(6)
        ->and($row->last_error)->toContain('HTTP 503 server_error: Boom');
});

it('reuses the last backoff value when tries exceed the list', function () {
    expect((new SendAuditLogs([payload()], 10, [10, 30]))->delayForAttempt(7))->toBe(30);
});

it('honours Retry-After on 429', function () {
    Http::fake(['audit.test/*' => Http::response(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']], 429, ['Retry-After' => '42'])]);

    runJob(new SendAuditLogs([payload()]))->shouldHaveReceived('release')->once()->with(42);
});

it('retries network errors', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    runJob(new SendAuditLogs([payload()]))->shouldHaveReceived('release')->once()->with(10);
});

it('stores only the rejected items of a partially accepted batch', function () {
    Http::fake(['audit.test/*' => Http::response(['data' => [
        'batch_uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X',
        'status' => 'queued',
        'total' => 3,
        'accepted' => 1,
        'duplicates' => 1,
        'rejected' => 1,
        'items' => [
            ['index' => 0, 'status' => 'accepted', 'uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8A', 'external_id' => 'a'],
            ['index' => 1, 'status' => 'rejected', 'errors' => ['tags.0' => ['The tags.0 may not be greater than 50 characters.']]],
            ['index' => 2, 'status' => 'duplicate', 'uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8C', 'external_id' => 'c'],
        ],
    ]], 202)]);

    runJob(new SendAuditLogs([payload('a'), payload('b'), payload('c')]))->shouldNotHaveReceived('release');

    $row = DB::table('audit_outbox')->sole();
    expect($row->external_id)->toBe('b')
        ->and($row->last_error)->toContain('tags.0');
});

it('keeps per-item errors when a whole batch is rejected', function () {
    Http::fake(['audit.test/*' => Http::response(['error' => [
        'code' => 'validation',
        'message' => 'No log in the batch is valid.',
        'details' => ['items' => [
            ['index' => 0, 'errors' => ['event' => ['The event field is required.']]],
            ['index' => 1, 'errors' => ['action' => ['The action format is invalid.']]],
        ]],
    ]], 422)]);

    runJob(new SendAuditLogs([payload('a'), payload('b')]))->shouldNotHaveReceived('release');

    $errors = DB::table('audit_outbox')->orderBy('id')->pluck('last_error', 'external_id')->all();
    expect($errors['a'])->toContain('event field is required')
        ->and($errors['b'])->toContain('action format is invalid');
});

it('does not retry on the sync connection but keeps the logs in the outbox', function () {
    Http::fake(['audit.test/*' => Http::response('', 500)]);

    expect(Audit::log('order.paid'))->not->toBeNull();

    Http::assertSentCount(1);
    expect(DB::table('audit_outbox')->count())->toBe(1);
});

it('keeps the logs in the outbox when the job cannot even be queued', function () {
    config(['audit-client.queue.connection' => 'missing-connection']);

    Audit::log('order.paid');

    $row = DB::table('audit_outbox')->sole();
    expect((int) $row->attempts)->toBe(0)
        ->and($row->last_error)->toContain('Could not be queued');
});

it('keeps the logs when the worker gives up on the job', function () {
    (new SendAuditLogs([payload()]))->failed(new RuntimeException('Timed out'));

    expect(DB::table('audit_outbox')->sole()->last_error)->toBe('Job failed: Timed out');
});
