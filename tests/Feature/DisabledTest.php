<?php

declare(strict_types=1);

use Company\AuditClient\Facades\Audit;
use Company\AuditClient\Tests\Fixtures\BlogPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['audit-client.enabled' => false]);
    Http::fake();
    Queue::fake();
});

it('sends, queues and buffers nothing when disabled', function () {
    config(['audit-client.batch.enabled' => true]);

    expect(Audit::log('order.paid'))->toBeNull()
        ->and(Audit::event('order.paid')->send())->toBeNull()
        ->and(Audit::buffered())->toBe(0);

    BlogPost::create(['title' => 'Hello'])->update(['title' => 'Bye']);
    $this->app->terminate();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(DB::table('audit_outbox')->count())->toBe(0);
});
