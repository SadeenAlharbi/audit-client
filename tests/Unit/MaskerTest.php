<?php

declare(strict_types=1);

use Company\AuditClient\Security\Masker;

beforeEach(function () {
    $this->masker = new Masker(['password', 'token', 'card_number', 'authorization']);
});

it('masks sensitive keys at any depth', function () {
    $masked = $this->masker->mask([
        'name' => 'Sara',
        'password' => 'secret',
        'profile' => ['payment' => ['card_number' => '4111111111111111', 'brand' => 'visa']],
        'devices' => [['token' => 'abc', 'os' => 'ios'], ['token' => 'def', 'os' => 'android']],
    ]);

    expect($masked)->toBe([
        'name' => 'Sara',
        'password' => '********',
        'profile' => ['payment' => ['card_number' => '********', 'brand' => 'visa']],
        'devices' => [['token' => '********', 'os' => 'ios'], ['token' => '********', 'os' => 'android']],
    ]);
});

it('matches keys case-insensitively', function () {
    expect($this->masker->mask(['PassWord' => 'x', 'TOKEN' => 'y', 'Authorization' => 'Bearer z']))
        ->toBe(['PassWord' => '********', 'TOKEN' => '********', 'Authorization' => '********']);
});

it('masks a whole nested value under a sensitive key and keeps nulls', function () {
    expect($this->masker->mask(['token' => ['a' => 1], 'password' => null]))
        ->toBe(['token' => '********', 'password' => null]);
});

it('leaves other keys and null input untouched', function () {
    expect($this->masker->mask(['password_hint' => 'cat', 'tokens_left' => 3]))
        ->toBe(['password_hint' => 'cat', 'tokens_left' => 3])
        ->and($this->masker->mask(null))->toBeNull();
});

it('uses the configured default list and replacement', function () {
    $masker = app(Masker::class);

    expect($masker->mask(['api_key' => 'k', 'Refresh_Token' => 'r', 'cvv' => '123', 'password_confirmation' => 'p']))
        ->toBe(['api_key' => '********', 'Refresh_Token' => '********', 'cvv' => '********', 'password_confirmation' => '********']);
});
