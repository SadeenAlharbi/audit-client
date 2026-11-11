<?php

declare(strict_types=1);

use Company\AuditClient\Security\Signer;

it('builds the canonical string from timestamp, upper-case method, uri and raw body', function () {
    expect(Signer::canonical('1731320130', 'post', '/api/v1/logs', '{"a":1}'))
        ->toBe("1731320130\nPOST\n/api/v1/logs\n{\"a\":1}");
});

it('produces the exact signature for a known input', function () {
    // Reference values computed independently with:
    // printf '%s\n%s\n%s\n%s' 1731320130 POST /api/v1/logs '<body>' | openssl dgst -sha256 -hmac test-signing-secret
    $signer = new Signer('test-signing-secret');

    expect($signer->sign('1731320130', 'POST', '/api/v1/logs', '{"event":"order.paid","action":"update"}'))
        ->toBe('042a9db36392467b1212594185e74c339e7ce51dc2d0d648d0f084e1f0f2f324');
});

it('signs the query string exactly as sent and an empty body for GET', function () {
    $signer = new Signer('test-signing-secret');

    expect($signer->sign('1731320130', 'get', '/api/v1/logs?severity=critical&per_page=10', ''))
        ->toBe('c0b42e25047ff70fb5d2c80a1dc3436602c504765cbe163f0f5e6c6c35acbdb4');
});

it('returns lower-case hex headers with the given timestamp', function () {
    $headers = (new Signer('s'))->headers('POST', '/api/v1/logs', '{}', 1700000000);

    expect($headers['X-Timestamp'])->toBe('1700000000')
        ->and($headers['X-Signature'])->toMatch('/^[0-9a-f]{64}$/');
});
