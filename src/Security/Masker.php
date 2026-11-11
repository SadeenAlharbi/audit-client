<?php

declare(strict_types=1);

namespace Company\AuditClient\Security;

/**
 * Replaces the values of sensitive keys, at any depth, before a log leaves
 * the application. Keys are matched case-insensitively; null values stay
 * null so "was it set?" remains visible without revealing the value.
 */
final class Masker
{
    /** @var array<string, true> */
    private readonly array $keys;

    /**
     * @param  iterable<string>  $keys
     */
    public function __construct(iterable $keys, private readonly string $replacement = '********')
    {
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[strtolower($key)] = true;
        }

        $this->keys = $normalized;
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public function mask(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = $value === null ? null : $this->replacement;
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        return isset($this->keys[strtolower($key)]);
    }
}
