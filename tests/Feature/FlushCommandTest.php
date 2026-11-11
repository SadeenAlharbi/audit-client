<?php

declare(strict_types=1);

use Company\AuditClient\Outbox\Outbox;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function outboxPayload(int $i): array
{
    return [
        'external_id' => "ext-{$i}",
        'event' => 'order.paid',
        'action' => 'update',
        'severity' => 'info',
        'occurred_at' => '2026-11-11T10:00:00.000Z',
    ];
}

function batchAccepted(Request $request): array
{
    if (! str_ends_with($request->url(), '/logs/batch')) {
        return ['data' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'accepted']];
    }

    $logs = $request->data()['logs'];

    return ['data' => [
        'batch_uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X',
        'status' => 'queued',
        'total' => count($logs),
        'accepted' => count($logs),
        'duplicates' => 0,
        'rejected' => 0,
        'items' => array_map(fn (int $i) => ['index' => $i, 'status' => 'accepted', 'uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X'], array_keys($logs)),
    ]];
}

it('re-sends outbox entries in batches and deletes them on success', function () {
    app(Outbox::class)->store(array_map(outboxPayload(...), range(1, 5)), 'HTTP 503', 6);

    Http::fake(fn (Request $request) => Http::response(batchAccepted($request), 202));

    $this->artisan('audit:flush', ['--batch' => 2])
        ->expectsOutputToContain('5 sent, 0 failed, 0 remaining')
        ->assertSuccessful();

    Http::assertSentCount(3); // 2 + 2 via /logs/batch, the last one via /logs
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/logs/batch')
        && array_column($request->data()['logs'], 'external_id') === ['ext-1', 'ext-2']
        && signatureIsValid($request));
    expect(DB::table('audit_outbox')->count())->toBe(0);
});

it('keeps rejected entries with an incremented attempts count and the last error', function () {
    app(Outbox::class)->store([outboxPayload(1), outboxPayload(2)], 'HTTP 503', 6);

    Http::fake(['audit.test/*' => Http::response(['data' => [
        'batch_uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'queued', 'total' => 2, 'accepted' => 1, 'duplicates' => 0, 'rejected' => 1,
        'items' => [
            ['index' => 0, 'status' => 'accepted', 'uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X'],
            ['index' => 1, 'status' => 'rejected', 'errors' => ['occurred_at' => ['The occurred_at is older than the retention period.']]],
        ],
    ]], 202)]);

    $this->artisan('audit:flush')->assertFailed();

    $row = DB::table('audit_outbox')->sole();
    expect($row->external_id)->toBe('ext-2')
        ->and((int) $row->attempts)->toBe(7)
        ->and($row->last_error)->toContain('older than the retention period');
});

it('treats duplicates as delivered', function () {
    app(Outbox::class)->store([outboxPayload(1)], 'HTTP 503', 6);

    Http::fake(['audit.test/*' => Http::response(['error' => ['code' => 'duplicate', 'message' => 'Already received.', 'details' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X']]], 409)]);

    $this->artisan('audit:flush')->assertSuccessful();

    expect(DB::table('audit_outbox')->count())->toBe(0);
});

it('stops when the platform is still unavailable', function () {
    app(Outbox::class)->store(array_map(outboxPayload(...), range(1, 4)), 'HTTP 503', 6);

    Http::fake(['audit.test/*' => Http::response('', 503)]);

    $this->artisan('audit:flush', ['--batch' => 2])
        ->expectsOutputToContain('unavailable')
        ->assertFailed();

    Http::assertSentCount(1);
    expect(DB::table('audit_outbox')->pluck('attempts', 'external_id')->map(fn ($a) => (int) $a)->all())
        ->toBe(['ext-1' => 7, 'ext-2' => 7, 'ext-3' => 6, 'ext-4' => 6]);
});

it('respects --limit', function () {
    app(Outbox::class)->store(array_map(outboxPayload(...), range(1, 3)), 'HTTP 503', 6);

    Http::fake(['audit.test/*' => Http::response(['data' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'accepted']], 202)]);

    $this->artisan('audit:flush', ['--limit' => 1])->assertSuccessful();

    expect(DB::table('audit_outbox')->pluck('external_id')->all())->toBe(['ext-2', 'ext-3']);
});

it('works with the file outbox driver', function () {
    $path = sys_get_temp_dir().'/audit-client-'.uniqid().'/outbox.jsonl';
    config(['audit-client.outbox.driver' => 'file', 'audit-client.outbox.path' => $path]);

    $outbox = app(Outbox::class);
    $outbox->store([outboxPayload(1), outboxPayload(2), outboxPayload(3)], 'HTTP 503', 6);
    expect($outbox->count())->toBe(3);

    Http::fake(fn (Request $request) => Http::response(batchAccepted($request), 202));

    $this->artisan('audit:flush')->assertSuccessful();

    expect($outbox->count())->toBe(0);
    @unlink($path);
    @rmdir(dirname($path));
});
