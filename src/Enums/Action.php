<?php

declare(strict_types=1);

namespace Company\AuditClient\Enums;

/**
 * Common actions. The API accepts any lower-case action matching
 * ^[a-z][a-z0-9_.\-]*$ (max 32 chars), so plain strings work as well.
 */
enum Action: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Restore = 'restore';
    case Login = 'login';
    case Logout = 'logout';
    case FailedLogin = 'failed_login';
    case View = 'view';
    case Export = 'export';
    case Custom = 'custom';

    /**
     * Guess the action from the last segment of an event name
     * ("user.updated" -> update). Unknown verbs map to "custom".
     */
    public static function fromEvent(string $event): self
    {
        $verb = strtolower((string) preg_replace('/^.*[.:\/]/', '', $event));

        return match ($verb) {
            'create', 'created' => self::Create,
            'update', 'updated' => self::Update,
            'delete', 'deleted', 'force_deleted' => self::Delete,
            'restore', 'restored' => self::Restore,
            'login' => self::Login,
            'logout' => self::Logout,
            'failed_login' => self::FailedLogin,
            'view', 'viewed' => self::View,
            'export', 'exported' => self::Export,
            default => self::Custom,
        };
    }
}
