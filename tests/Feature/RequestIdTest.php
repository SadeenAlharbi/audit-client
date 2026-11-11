<?php

declare(strict_types=1);

use Company\AuditClient\Facades\Audit;
use Company\AuditClient\Http\Middleware\AddRequestId;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Http::fake(['audit.test/*' => Http::response(['data' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'accepted']], 202)]);

    Route::middleware('audit.request-id')->post('/orders', function () {
        Audit::log('order.created');
        Audit::log('order.paid');

        return response()->json(['ok' => true]);
    });
});

it('attaches the incoming X-Request-Id to every log and echoes it on the response', function () {
    $this->postJson('/orders', [], ['X-Request-Id' => 'req-123', 'User-Agent' => 'TestAgent/1.0'])
        ->assertOk()
        ->assertHeader('X-Request-Id', 'req-123');

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) {
        $log = $request->data();
        assertLogInput($log);

        return $log['request_id'] === 'req-123'
            && $log['actor'] === ['ip' => '127.0.0.1', 'user_agent' => 'TestAgent/1.0'];
    });
});

it('creates a request id when none (or an invalid one) is sent', function (array $headers) {
    $response = $this->postJson('/orders', [], $headers)->assertOk();

    $id = $response->headers->get('X-Request-Id');
    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    Http::assertSent(fn (Request $request) => $request->data()['request_id'] === $id);
})->with([
    'missing' => [[]],
    'invalid' => [['X-Request-Id' => str_repeat('x', 65)]],
]);

it('is available as a class for global registration', function () {
    expect(app('router')->getMiddleware()['audit.request-id'])->toBe(AddRequestId::class);
});

it('does not add request context outside HTTP requests', function () {
    Audit::log('cron.ran');

    Http::assertSent(fn (Request $request) => ! isset($request->data()['request_id']) && ! isset($request->data()['actor']));
});
