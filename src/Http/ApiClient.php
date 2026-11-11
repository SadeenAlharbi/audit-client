<?php

declare(strict_types=1);

namespace Company\AuditClient\Http;

use Company\AuditClient\Security\Signer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Low-level HTTP access to the Audit Log Hub API: authentication headers,
 * JSON encoding and request signing. It knows nothing about retries or
 * outboxes; anything it does is a plain, documented API call.
 */
final class ApiClient
{
    public const USER_AGENT = 'company-audit-client/1.0';

    private readonly string $origin;

    private readonly string $basePath;

    public function __construct(
        string $endpoint,
        private readonly string $project,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly ?Signer $signer = null,
        private readonly int $timeout = 5,
        private readonly int $connectTimeout = 2,
    ) {
        $parts = parse_url(rtrim($endpoint, '/'));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The audit-client endpoint must be an absolute URL, e.g. https://audit.example.com/api/v1.');
        }

        $this->origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->basePath = $parts['path'] ?? '';
    }

    /**
     * Send one request. $path is relative to the endpoint (e.g. "logs/batch").
     *
     * @param  array<string, mixed>  $query
     * @param  array<array-key, mixed>|null  $payload
     *
     * @throws ConnectionException
     * @throws \JsonException
     */
    public function send(string $method, string $path, array $query = [], ?array $payload = null): Response
    {
        $method = strtoupper($method);
        $uri = $this->uri($path, $query);
        $body = $payload === null ? '' : self::encode($payload);

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'X-Project' => $this->project,
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];

        if ($this->signer !== null) {
            $headers = array_merge($headers, $this->signer->headers($method, $uri, $body));
        }

        $request = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, $this->origin.$uri);
    }

    /**
     * The request URI exactly as sent and signed: path, then "?query" if any.
     *
     * @param  array<string, mixed>  $query
     */
    public function uri(string $path, array $query = []): string
    {
        $uri = $this->basePath.'/'.ltrim($path, '/');

        if ($query !== []) {
            $uri .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws \JsonException
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
