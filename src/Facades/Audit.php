<?php

declare(strict_types=1);

namespace Company\AuditClient\Facades;

use Company\AuditClient\AuditManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null log(string $event, mixed $actor = null, mixed $subject = null, array<array-key, mixed>|null $old = null, array<array-key, mixed>|null $new = null, array<string, mixed> $options = [])
 * @method static \Company\AuditClient\PendingLog event(string $event)
 * @method static string|null record(\Company\AuditClient\PendingLog $log)
 * @method static int flush()
 * @method static int buffered()
 * @method static bool enabled()
 * @method static void setRequestId(string|null $requestId)
 * @method static string|null requestId()
 * @method static \Company\AuditClient\Http\ApiClient client()
 *
 * @see AuditManager
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditManager::class;
    }
}
