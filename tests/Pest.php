<?php

declare(strict_types=1);

use Company\AuditClient\Security\Signer;
use Company\AuditClient\Tests\TestCase;
use Illuminate\Http\Client\Request;

uses(TestCase::class)->in('Feature', 'Unit');

/** Top-level keys of the OpenAPI LogInput schema. */
const LOG_INPUT_KEYS = [
    'external_id', 'event', 'action', 'severity', 'occurred_at', 'actor', 'subject',
    'old_values', 'new_values', 'metadata', 'tags', 'request_id', 'correlation_id',
];

/**
 * Assert a payload uses only LogInput fields (unknown fields are rejected by the API).
 *
 * @param  array<string, mixed>  $log
 */
function assertLogInput(array $log): void
{
    expect(array_diff(array_keys($log), LOG_INPUT_KEYS))->toBe([])
        ->and($log)->toHaveKeys(['event', 'action', 'occurred_at', 'external_id'])
        ->and($log['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and($log['event'])->toMatch('/^[A-Za-z0-9][A-Za-z0-9_.:\-\/]*$/')
        ->and($log['action'])->toMatch('/^[a-z][a-z0-9_.\-]*$/');

    if (isset($log['actor'])) {
        expect(array_diff(array_keys($log['actor']), ['type', 'id', 'name', 'ip', 'user_agent']))->toBe([]);
    }

    if (isset($log['subject'])) {
        expect(array_diff(array_keys($log['subject']), ['type', 'id']))->toBe([]);
    }
}

/**
 * Recompute the signature of a sent request with the documented algorithm.
 */
function signatureIsValid(Request $request): bool
{
    $uri = (string) parse_url($request->url(), PHP_URL_PATH);
    $query = parse_url($request->url(), PHP_URL_QUERY);
    $uri .= $query ? '?'.$query : '';

    $canonical = $request->header('X-Timestamp')[0]."\n".$request->method()."\n".$uri."\n".$request->body();

    return hash_equals(hash_hmac('sha256', $canonical, TestCase::SECRET), $request->header('X-Signature')[0] ?? '')
        && (new Signer(TestCase::SECRET))->sign($request->header('X-Timestamp')[0], $request->method(), $uri, $request->body()) === $request->header('X-Signature')[0];
}

/**
 * @return list<array<string, mixed>>
 */
function sentLogs(Request $request): array
{
    $body = $request->data();

    return str_ends_with($request->url(), '/logs/batch') ? $body['logs'] : [$body];
}
