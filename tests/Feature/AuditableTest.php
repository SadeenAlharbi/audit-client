<?php

declare(strict_types=1);

use Company\AuditClient\Tests\Fixtures\BlogPost;
use Company\AuditClient\Tests\Fixtures\Comment;
use Company\AuditClient\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['audit.test/*' => Http::response(['data' => ['uid' => '01JCDE0Z8Y2K7Q4M9V3N5T6W8X', 'status' => 'accepted']], 202)]);
});

/**
 * @return list<array<string, mixed>>
 */
function sentModelLogs(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values()->all();
}

it('logs created with the new attributes, honouring $hidden, $auditExclude and the mask list', function () {
    $post = BlogPost::create([
        'title' => 'Hello',
        'published' => true,
        'settings' => ['theme' => 'dark', 'token' => 't-1'],
        'draft_notes' => 'private',
        'views' => 5,
        'api_key' => 'k-1',
    ]);

    [$log] = sentModelLogs();
    assertLogInput($log);

    expect($log['event'])->toBe('blog_post.created')
        ->and($log['action'])->toBe('create')
        ->and($log['subject'])->toBe(['type' => 'blog_post', 'id' => (string) $post->id])
        ->and($log)->not->toHaveKey('old_values')
        ->and($log['new_values'])->toMatchArray([
            'title' => 'Hello',
            'published' => true,
            'settings' => ['theme' => 'dark', 'token' => '********'],
            'api_key' => '********',
        ])
        ->and($log['new_values'])->not->toHaveKeys(['draft_notes', 'views']);
});

it('logs updated with only the changed attributes', function () {
    $post = BlogPost::create(['title' => 'Hello', 'status' => 'draft']);
    $this->travel(5)->seconds();

    $post->update(['status' => 'published', 'views' => 10]);

    $log = sentModelLogs()[1];
    assertLogInput($log);

    expect($log['event'])->toBe('blog_post.updated')
        ->and($log['action'])->toBe('update')
        ->and(array_keys($log['old_values']))->toEqualCanonicalizing(['status', 'updated_at'])
        ->and($log['old_values']['status'])->toBe('draft')
        ->and($log['new_values']['status'])->toBe('published')
        ->and($log['old_values']['updated_at'])->not->toBe($log['new_values']['updated_at']);
});

it('skips updates that only touch excluded attributes', function () {
    $post = BlogPost::create(['title' => 'Hello']);

    BlogPost::withoutTimestamps(fn () => $post->update(['views' => 99, 'draft_notes' => 'x']));

    expect(sentModelLogs())->toHaveCount(1);
});

it('logs deleted and restored', function () {
    $post = BlogPost::create(['title' => 'Hello']);

    $post->delete();
    $post->restore();

    [, $deleted, $restored] = sentModelLogs();

    expect($deleted['event'])->toBe('blog_post.deleted')
        ->and($deleted['action'])->toBe('delete')
        ->and($deleted['old_values']['title'])->toBe('Hello')
        ->and($deleted)->not->toHaveKey('new_values')
        ->and($restored['event'])->toBe('blog_post.restored')
        ->and($restored['action'])->toBe('restore')
        ->and($restored['new_values']['deleted_at'])->toBeNull();
});

it('uses the authenticated user as actor', function () {
    $user = User::create(['name' => 'Sara', 'email' => 's@example.com', 'password' => 'x']);
    $this->actingAs($user);

    BlogPost::create(['title' => 'Hello']);

    expect(sentModelLogs()[0]['actor'])->toBe(['type' => 'user', 'id' => (string) $user->id, 'name' => 'Sara']);
});

it('never interrupts the model operation when the platform fails', function () {
    Http::fake(['audit.test/*' => Http::response('', 500)]);

    $post = BlogPost::create(['title' => 'Hello']);

    expect($post->exists)->toBeTrue();
});

it('works on models without $auditExclude or SoftDeletes', function () {
    $comment = Comment::create(['body' => 'First']);
    $comment->update(['body' => 'Edited']);
    $comment->delete();

    expect(array_column(sentModelLogs(), 'event'))->toBe(['comment.created', 'comment.updated', 'comment.deleted'])
        ->and(sentModelLogs()[1]['new_values']['body'])->toBe('Edited');
});
