<?php

declare(strict_types=1);

namespace Company\AuditClient\Security;

/**
 * Replaces the values of sensitive keys, at any depth, before a log leaves
 * the application. Null values stay null so "was it set?" remains visible
 * without revealing the value.
 *
 * A key matches a configured key the same way the platform matches (so the
 * client never sends what the platform would only mask later):
 *  - its words contain the configured key's words, in order: "password"
 *    matches "user_password", "newPassword", "password2"; "card_number"
 *    matches "billingCardNumber"; "pin" matches "PINCode" but not "shipping";
 *  - or, for configured keys of 5+ letters, the key without separators ends
 *    with it: "dbpassword", "mysecret", "apitoken" (but not "secretary").
 *
 * Values that are credentials whatever their key ("Bearer ..." / "Basic ..."
 * headers, JWTs, Audit Log Hub API keys) are replaced too.
 */
final class Masker
{
    /** @var list<list<string>> */
    private readonly array $needles;

    /** @var list<string> compacted needles of 5+ letters, matched as suffix */
    private readonly array $suffixes;

    /**
     * @param  iterable<string>  $keys
     */
    public function __construct(iterable $keys, private readonly string $replacement = '********')
    {
        $needles = [];
        foreach ($keys as $key) {
            $words = self::words($key);
            if ($words !== []) {
                $needles[implode(' ', $words)] = $words;
            }
        }

        $this->needles = array_values($needles);
        $this->suffixes = array_values(array_filter(
            array_map(static fn (array $words): string => implode('', $words), $this->needles),
            static fn (string $compact): bool => strlen($compact) >= 5,
        ));
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
            } elseif (is_string($value) && self::looksLikeSecret($value)) {
                $data[$key] = $this->replacement;
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        $words = self::words($key);
        $count = count($words);
        $compact = implode('', $words);

        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($compact, $suffix)) {
                return true;
            }
        }

        foreach ($this->needles as $needle) {
            $length = count($needle);
            for ($i = 0; $i + $length <= $count; $i++) {
                if (array_slice($words, $i, $length) === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Credentials recognisable by their value, whatever the key. */
    public static function looksLikeSecret(string $value): bool
    {
        return preg_match('/^\s*(Bearer|Basic|Digest)\s+\S{8,}/i', $value) === 1
            || preg_match('/^eyJ[\w-]{8,}\.[\w-]{8,}\.[\w-]+$/', $value) === 1      // JWT
            || preg_match('/^alh_[A-Za-z0-9]{40,}$/', $value) === 1;                 // Audit Log Hub API key
    }

    /**
     * "accessToken" / "access-token" / "ACCESS_TOKEN" -> ["access", "token"];
     * "APIToken" -> ["api", "token"]; "password2" -> ["password", "2"].
     *
     * @return list<string>
     */
    private static function words(string $key): array
    {
        $spaced = (string) preg_replace(
            ['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/', '/(?<=[A-Za-z])(?=[0-9])|(?<=[0-9])(?=[A-Za-z])/'],
            ' ',
            $key,
        );

        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }
}
