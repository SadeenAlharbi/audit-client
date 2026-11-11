<?php

declare(strict_types=1);

namespace Company\AuditClient\Support;

use Company\AuditClient\LogData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Converts what callers pass as actor / subject (Eloquent models,
 * Authenticatable users, arrays or null) into the LogInput actor and
 * subject objects.
 */
final class EntityResolver
{
    /** Max lengths of LogInput.actor string fields. */
    private const MAX_LENGTH = ['type' => 191, 'id' => 191, 'name' => 255, 'user_agent' => 512];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, string>|null
     */
    public function actor(mixed $actor, bool $resolveAuthenticated = true): ?array
    {
        if ($actor === null && $resolveAuthenticated) {
            $actor = $this->authenticatedUser();
        }

        $data = match (true) {
            $actor === null => [],
            is_array($actor) => Arr::only($actor, LogData::ACTOR_KEYS),
            $actor instanceof Model, $actor instanceof Authenticatable => $this->fromObject($actor, withName: true),
            default => throw new InvalidArgumentException('An audit actor must be a model, an Authenticatable, an array or null.'),
        };

        return $this->clean($data + $this->requestContext());
    }

    /**
     * @return array<string, string>|null
     */
    public function subject(mixed $subject): ?array
    {
        $data = match (true) {
            $subject === null => [],
            is_array($subject) => Arr::only($subject, LogData::SUBJECT_KEYS),
            $subject instanceof Model, $subject instanceof Authenticatable => $this->fromObject($subject, withName: false),
            default => throw new InvalidArgumentException('An audit subject must be a model, an Authenticatable, an array or null.'),
        };

        return $this->clean($data);
    }

    /**
     * The type used for models: the snake-cased class name (App\Models\BlogPost -> blog_post).
     */
    public static function typeOf(object $entity): string
    {
        return Str::snake(class_basename($entity));
    }

    /**
     * @return array<string, mixed>
     */
    private function fromObject(Model|Authenticatable $entity, bool $withName): array
    {
        $data = [
            'type' => self::typeOf($entity),
            'id' => $entity instanceof Authenticatable ? $entity->getAuthIdentifier() : $entity->getKey(),
        ];

        if ($withName && $entity instanceof Model) {
            $data['name'] = $entity->getAttribute('name');
        }

        return $data;
    }

    /**
     * IP and user agent of the current HTTP request. A request is only
     * considered "current" once it has been routed, so console commands and
     * queued jobs never report the placeholder console request.
     *
     * @return array<string, mixed>
     */
    private function requestContext(): array
    {
        if (! $this->container->bound('request')) {
            return [];
        }

        /** @var Request $request */
        $request = $this->container->make('request');

        if ($request->route() === null) {
            return [];
        }

        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }

    private function authenticatedUser(): ?Authenticatable
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        try {
            /** @var AuthFactory $auth */
            $auth = $this->container->make('auth');

            return $auth->guard()->user();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cast to strings, enforce the API max lengths and drop empty values.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>|null
     */
    private function clean(array $data): ?array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! (is_string($value) || is_int($value))) {
                continue;
            }

            $value = (string) $value;
            if ($value === '') {
                continue;
            }

            $clean[$key] = isset(self::MAX_LENGTH[$key]) ? mb_substr($value, 0, self::MAX_LENGTH[$key]) : $value;
        }

        return $clean === [] ? null : $clean;
    }
}
