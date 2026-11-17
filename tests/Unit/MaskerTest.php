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
    expect($this->masker->mask(['tokens_left' => 3, 'secretary' => 'Reem', 'shipping' => 'DHL', 'company' => 'QU']))
        ->toBe(['tokens_left' => 3, 'secretary' => 'Reem', 'shipping' => 'DHL', 'company' => 'QU'])
        ->and($this->masker->mask(null))->toBeNull();
});

it('matches keys by their words, like the platform', function () {
    $masker = app(Masker::class);

    $masked = $masker->mask([
        'user_password' => 'a', 'newPassword' => 'b', 'password2' => 'c', 'DBPassword' => 'd',
        'remember_token' => 'e', 'accessToken' => 'f', 'APIToken' => 'g', 'billingCardNumber' => 'h',
        'PINCode' => 'i', 'mysecret' => 'j', 'password_hint' => 'k',
    ]);

    expect(array_unique(array_values($masked)))->toBe(['********']);
});

it('masks values that are credentials whatever the key', function () {
    expect($this->masker->mask([
        'note' => 'Bearer abcdefghijklmnop',
        'header' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTYifQ.signature',
        'key' => 'alh_'.str_repeat('A', 40),
        'city' => 'Buraydah',
    ]))->toBe(['note' => '********', 'header' => '********', 'key' => '********', 'city' => 'Buraydah']);
});

it('uses the configured default list and replacement', function () {
    $masker = app(Masker::class);

    expect($masker->mask(['api_key' => 'k', 'Refresh_Token' => 'r', 'cvv' => '123', 'password_confirmation' => 'p']))
        ->toBe(['api_key' => '********', 'Refresh_Token' => '********', 'cvv' => '********', 'password_confirmation' => '********']);
});
