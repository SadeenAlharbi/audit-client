<?php

declare(strict_types=1);

namespace Company\AuditClient\Http\Middleware;

use Closure;
use Company\AuditClient\AuditManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads X-Request-Id (or creates a UUID), attaches it to every log of the
 * request (and to jobs it queues) as request_id, and echoes it on the
 * response. Register it globally or on a group, alias "audit.request-id".
 */
final class AddRequestId
{
    public const HEADER = 'X-Request-Id';

    /** Accepted incoming ids; anything else is replaced (API max length is 64). */
    private const PATTERN = '/^[A-Za-z0-9._:\-]{1,64}$/';

    public function __construct(private readonly AuditManager $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get(self::HEADER);

        if (! is_string($id) || preg_match(self::PATTERN, $id) !== 1) {
            $id = (string) Str::uuid();
            $request->headers->set(self::HEADER, $id);
        }

        $this->audit->setRequestId($id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
