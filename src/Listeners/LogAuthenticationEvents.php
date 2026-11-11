<?php

declare(strict_types=1);

namespace Company\AuditClient\Listeners;

use Company\AuditClient\AuditManager;
use Company\AuditClient\Enums\Action;
use Company\AuditClient\Enums\Severity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Logs authentication events (enabled with audit-client.listen_auth_events):
 *
 *   Login  -> auth.login  (login)
 *   Logout -> auth.logout (logout)
 *   Failed -> auth.failed (failed_login, warning)
 *
 * For failed attempts the submitted credentials are kept in metadata with
 * the password (and any other mask-listed key) masked.
 */
final class LogAuthenticationEvents
{
    public function __construct(private readonly AuditManager $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->event('auth.login')
            ->action(Action::Login)
            ->by($event->user)
            ->on($event->user)
            ->metadata(['guard' => $event->guard, 'remember' => $event->remember])
            ->send();
    }

    public function handleLogout(Logout $event): void
    {
        $this->audit->event('auth.logout')
            ->action(Action::Logout)
            ->by($event->user)
            ->on($event->user)
            ->metadata(['guard' => $event->guard])
            ->send();
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->event('auth.failed')
            ->action(Action::FailedLogin)
            ->severity(Severity::Warning)
            ->by($event->user)
            ->on($event->user)
            ->metadata(['guard' => $event->guard, 'credentials' => $event->credentials])
            ->send();
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }
}
