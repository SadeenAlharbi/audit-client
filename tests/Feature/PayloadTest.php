<?php

declare(strict_types=1);

use Company\AuditClient\Facades\Audit;
use Company\AuditClient\Jobs\SendAuditLogs;
use Company\AuditClient\Tests\Fixtures\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::fake(['audit.test/*' => Http::response(['data' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'accepted']], 202)]);
});

it('sends one log through the queued job with the exact LogInput shape', function () {
    Carbon::setTestNow('2026-11-11 10:15:30.120');
    $user = User::create(['name' => 'Sara Ali', 'email' => 'sara@example.com', 'password' => 'x']);

    $externalId = Audit::log(
        'order.status_changed',
        $user,
        ['type' => 'order', 'id' => 1001, 'colour' => 'red'],
        ['status' => 'pending'],
        ['status' => 'paid', 'card_number' => '4111111111111111'],
        [
            'action' => 'update',
            'severity' => 'warning',
            'metadata' => ['channel' => 'web', 'Authorization' => 'Bearer abc'],
            'tags' => ['orders', 'payments'],
            'correlation_id' => 'checkout-7f3a',
        ],
    );

    expect($externalId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($user, $externalId) {
        $log = $request->data();
        assertLogInput($log);

        expect($request->url())->toBe('https://audit.test/api/v1/logs')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer alh_test_key'])
            ->and($request->header('X-Project'))->toBe(['shop'])
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and($request->header('Accept'))->toBe(['application/json'])
            ->and(signatureIsValid($request))->toBeTrue()
            ->and($log)->toBe([
                'external_id' => $externalId,
                'event' => 'order.status_changed',
                'action' => 'update',
                'severity' => 'warning',
                'occurred_at' => '2026-11-11T10:15:30.120Z',
                'actor' => ['type' => 'user', 'id' => (string) $user->id, 'name' => 'Sara Ali'],
                'subject' => ['type' => 'order', 'id' => '1001'],
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'paid', 'card_number' => '********'],
                'metadata' => ['channel' => 'web', 'Authorization' => '********'],
                'tags' => ['orders', 'payments'],
                'correlation_id' => 'checkout-7f3a',
            ]);

        return true;
    });
});

it('omits empty optional fields and infers the action', function () {
    Audit::log('report.exported');

    Http::assertSent(function (Request $request) {
        $log = $request->data();
        assertLogInput($log);

        expect(array_keys($log))->toBe(['external_id', 'event', 'action', 'severity', 'occurred_at'])
            ->and($log['action'])->toBe('export')
            ->and($log['severity'])->toBe('info');

        return true;
    });
});

it('converts occurred_at to UTC with milliseconds', function () {
    Audit::log('order.paid', options: ['occurred_at' => '2026-11-11T13:00:00.5+03:00']);

    Http::assertSent(fn (Request $request) => $request->data()['occurred_at'] === '2026-11-11T10:00:00.500Z');
});

it('uses the authenticated user as actor when none is given', function () {
    $user = User::create(['name' => 'Omar', 'email' => 'omar@example.com', 'password' => 'x']);
    $this->actingAs($user);

    Audit::log('profile.viewed');

    Http::assertSent(fn (Request $request) => $request->data()['actor'] === ['type' => 'user', 'id' => (string) $user->id, 'name' => 'Omar']);
});

it('offers a fluent builder', function () {
    Audit::event('order.paid')
        ->by(['type' => 'system', 'id' => 'billing-cron'])
        ->on(['type' => 'order', 'id' => '7'])
        ->changes(['status' => 'pending'], ['status' => 'paid'])
        ->severity('critical')
        ->tags('billing', ['money'])
        ->externalId('order-7-paid')
        ->send();

    Http::assertSent(function (Request $request) {
        expect($request->data())->toMatchArray([
            'external_id' => 'order-7-paid',
            'action' => 'custom',
            'severity' => 'critical',
            'actor' => ['type' => 'system', 'id' => 'billing-cron'],
            'tags' => ['billing', 'money'],
        ]);

        return true;
    });
});

it('pushes the job onto the configured connection and queue with a reusable external_id', function () {
    Queue::fake();
    config(['audit-client.queue.connection' => 'redis', 'audit-client.queue.name' => 'audit']);

    $externalId = Audit::log('user.updated', null, null, ['a' => 1], ['a' => 2]);

    Queue::assertPushedOn('audit', SendAuditLogs::class, function (SendAuditLogs $job) use ($externalId) {
        return $job->connection === 'redis'
            && $job->tries === 6
            && $job->backoff() === [10, 30, 90, 270, 810]
            && $job->logs[0]['external_id'] === $externalId
            && $job->logs[0]['old_values'] === ['a' => 1];
    });
    Http::assertNothingSent();
});

it('does not sign requests when no secret is configured', function () {
    config(['audit-client.hmac_secret' => null]);

    Audit::log('order.paid');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Signature') && ! $request->hasHeader('X-Timestamp'));
});

it('reports invalid input instead of throwing', function () {
    expect(Audit::log('order.paid', options: ['colour' => 'red']))->toBeNull()
        ->and(Audit::log('not a valid event!'))->toBeNull()
        ->and(Audit::event('order.paid')->action('Not Valid')->send())->toBeNull()
        ->and(Audit::log('order.paid', options: ['severity' => 'fatal']))->toBeNull();

    Http::assertNothingSent();
});
