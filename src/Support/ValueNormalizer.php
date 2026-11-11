<?php

declare(strict_types=1);

namespace Company\AuditClient\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Turns arbitrary values (dates, enums, models, collections...) into plain
 * JSON-safe data, so a log can be serialized onto the queue and encoded
 * as JSON without surprises.
 */
final class ValueNormalizer
{
    /** ISO-8601, UTC, milliseconds: 2026-11-11T10:15:30.120Z */
    public const DATE_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public static function date(DateTimeInterface|string $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);

        return $date->utc()->format(self::DATE_FORMAT);
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof DateTimeInterface => self::date($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Arrayable => self::normalize($value->toArray()),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize()),
            $value instanceof Stringable => (string) $value,
            is_object($value) => '['.$value::class.']',
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    public static function normalizeArray(?array $values): ?array
    {
        return $values === null ? null : array_map(self::normalize(...), $values);
    }
}
