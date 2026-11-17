<?php

declare(strict_types=1);

use Company\AuditClient\Tests\Fixtures\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['audit.test/*' => Http::response(['data' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'accepted']], 202)]);
    $this->user = User::create(['name' => 'Sara', 'email' => 'sara@example.com', 'password' => bcrypt('secret-password')]);
});

it('logs logins', function () {
    event(new Login('web', $this->user, true));

    Http::assertSent(function (Request $request) {
        $log = $request->data();
        assertLogInput($log);

        expect($log)->toMatchArray([
            'event' => 'auth.login',
            'action' => 'login',
            'severity' => 'info',
            'actor' => ['type' => 'user', 'id' => (string) $this->user->id, 'name' => 'Sara'],
            'subject' => ['type' => 'user', 'id' => (string) $this->user->id],
            'metadata' => ['guard' => 'web', 'remember' => true],
        ]);

        return true;
    });
});

it('logs logouts', function () {
    event(new Logout('web', $this->user));

    Http::assertSent(fn (Request $request) => $request->data()['event'] === 'auth.logout'
        && $request->data()['action'] === 'logout');
});

it('logs failed attempts as warnings with the login identifier, never the credentials', function () {
    Auth::attempt(['email' => 'sara@example.com', 'password' => 'wrong']);

    Http::assertSent(function (Request $request) {
        $log = $request->data();
        assertLogInput($log);

        expect($log)->toMatchArray([
            'event' => 'auth.failed',
            'action' => 'failed_login',
            'severity' => 'warning',
            'metadata' => ['guard' => 'web', 'login' => 'sara@example.com'],
        ])->and($log['actor']['id'])->toBe((string) $this->user->id);

        return true;
    });
});

it('logs failed attempts for unknown users without an actor', function () {
    event(new Failed('web', null, ['email' => 'nobody@example.com', 'password' => 'x']));

    Http::assertSent(fn (Request $request) => ! isset($request->data()['actor'])
        && $request->data()['metadata'] === ['guard' => 'web', 'login' => 'nobody@example.com']
        && ! str_contains(json_encode($request->data()), 'password'));
});
