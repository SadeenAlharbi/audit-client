<?php

declare(strict_types=1);

namespace Company\AuditClient\Tests\Feature;

use Company\AuditClient\Tests\Fixtures\User;
use Company\AuditClient\Tests\TestCase;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class AuthListenerDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('audit-client.listen_auth_events', false);
    }

    #[Test]
    public function auth_events_are_not_logged_when_disabled(): void
    {
        Http::fake();
        $user = User::create(['name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'x']);

        event(new Login('web', $user, false));

        Http::assertNothingSent();
    }
}
