<?php

declare(strict_types=1);

use Company\AuditClient\Facades\Audit;
use Company\AuditClient\Jobs\SendAuditLogs;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['audit-client.batch.enabled' => true, 'audit-client.batch.size' => 500]);
});

it('buffers logs and sends them together through POST /logs/batch', function () {
    Http::fake(['audit.test/*' => Http::response(['data' => ['batch_uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'queued', 'total' => 3, 'accepted' => 3, 'duplicates' => 0, 'rejected' => 0, 'items' => []]], 202)]);

    Audit::log('a.created');
    Audit::log('b.updated');
    Audit::log('c.deleted');

    expect(Audit::buffered())->toBe(3);
    Http::assertNothingSent();

    expect(Audit::flush())->toBe(3);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        expect($request->url())->toBe('https://audit.test/api/v1/logs/batch')
            ->and(array_keys($request->data()))->toBe(['logs'])
            ->and(array_column($request->data()['logs'], 'event'))->toBe(['a.created', 'b.updated', 'c.deleted'])
            ->and(signatureIsValid($request))->toBeTrue();

        foreach ($request->data()['logs'] as $log) {
            assertLogInput($log);
        }

        return true;
    });
});

it('chunks large buffers into batches of at most 500', function () {
    Queue::fake();

    for ($i = 0; $i < 1200; $i++) {
        Audit::log('item.created', options: ['metadata' => ['i' => $i]]);
    }

    // Two full batches were flushed as soon as the buffer filled up.
    expect(Audit::buffered())->toBe(200);
    Audit::flush();

    $sizes = Queue::pushed(SendAuditLogs::class)->map(fn (SendAuditLogs $job) => count($job->logs))->all();
    expect($sizes)->toBe([500, 500, 200]);
});

it('never sends more than 500 logs even if batch.size is larger', function () {
    Queue::fake();
    config(['audit-client.batch.size' => 2000]);

    for ($i = 0; $i < 501; $i++) {
        Audit::log('item.created');
    }

    Queue::assertPushed(SendAuditLogs::class, fn (SendAuditLogs $job) => count($job->logs) === 500);
    expect(Audit::buffered())->toBe(1);
});

it('flushes the buffer when the application terminates', function () {
    Queue::fake();

    Audit::log('a.created');
    Audit::log('b.created');
    Queue::assertNothingPushed();

    $this->app->terminate();

    Queue::assertPushed(SendAuditLogs::class, fn (SendAuditLogs $job) => count($job->logs) === 2);
    expect(Audit::buffered())->toBe(0);
});
