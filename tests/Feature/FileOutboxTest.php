<?php

declare(strict_types=1);

use Company\AuditClient\Outbox\FileOutbox;
use Company\AuditClient\Outbox\NullOutbox;
use Company\AuditClient\Outbox\Outbox;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/audit-client-'.uniqid().'/nested/outbox.jsonl';
    $this->outbox = new FileOutbox($this->path);
});

afterEach(function () {
    @unlink($this->path);
});

it('stores one JSON line per log and pages through them in order', function () {
    $this->outbox->store([['external_id' => 'a'], ['external_id' => 'b'], ['external_id' => 'c']], 'HTTP 503', 6);

    expect(file($this->path))->toHaveCount(3);

    $first = $this->outbox->pending(2);
    expect(array_map(fn ($e) => $e->payload['external_id'], $first))->toBe(['a', 'b'])
        ->and($first[0]->attempts)->toBe(6)
        ->and($first[0]->lastError)->toBe('HTTP 503');

    $rest = $this->outbox->pending(2, $first[1]->id);
    expect(array_map(fn ($e) => $e->payload['external_id'], $rest))->toBe(['c']);
});

it('deletes entries and records failures', function () {
    $this->outbox->store([['external_id' => 'a'], ['external_id' => 'b']], 'first', 1);
    [$a, $b] = $this->outbox->pending(10);

    $this->outbox->recordFailure([$b->id], 'second');
    $this->outbox->delete([$a->id]);

    $left = $this->outbox->pending(10);
    expect($left)->toHaveCount(1)
        ->and($left[0]->id)->toBe($b->id)
        ->and($left[0]->attempts)->toBe(2)
        ->and($left[0]->lastError)->toBe('second');
});

it('resolves the driver from config', function (string $driver, string $class) {
    config(['audit-client.outbox.driver' => $driver, 'audit-client.outbox.path' => $this->path]);

    expect(app(Outbox::class))->toBeInstanceOf($class);
})->with([
    ['file', FileOutbox::class],
    ['null', NullOutbox::class],
]);
