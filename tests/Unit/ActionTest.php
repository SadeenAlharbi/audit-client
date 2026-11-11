<?php

declare(strict_types=1);

use Company\AuditClient\Enums\Action;

it('infers the action from the event name', function (string $event, Action $action) {
    expect(Action::fromEvent($event))->toBe($action);
})->with([
    ['user.created', Action::Create],
    ['order.updated', Action::Update],
    ['post.deleted', Action::Delete],
    ['post.restored', Action::Restore],
    ['auth.login', Action::Login],
    ['report.exported', Action::Export],
    ['order.paid', Action::Custom],
    ['ping', Action::Custom],
]);
