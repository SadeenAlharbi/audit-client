<?php

declare(strict_types=1);

namespace Company\AuditClient\Security;

/**
 * HMAC-SHA256 request signing as defined by the Audit Log Hub API.
 *
 *   canonical   = {X-Timestamp}\n{METHOD}\n{path?query as sent}\n{raw body}
 *   X-Signature = lowercase hex( HMAC-SHA256( secret, canonical ) )
 */
final class Signer
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
    ) {}

    public static function canonical(string $timestamp, string $method, string $uri, string $body): string
    {
        return $timestamp."\n".strtoupper($method)."\n".$uri."\n".$body;
    }

    public function sign(string $timestamp, string $method, string $uri, string $body): string
    {
        return hash_hmac('sha256', self::canonical($timestamp, $method, $uri, $body), $this->secret);
    }

    /**
     * The X-Timestamp and X-Signature headers for one request.
     *
     * @return array{X-Timestamp: string, X-Signature: string}
     */
    public function headers(string $method, string $uri, string $body, ?int $timestamp = null): array
    {
        $timestamp = (string) ($timestamp ?? time());

        return [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->sign($timestamp, $method, $uri, $body),
        ];
    }
}
