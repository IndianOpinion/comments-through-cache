<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Reads the plugin's constants once per request and exposes typed getters.
 *
 * Constants live in wp-config.php. Filters that take a comment or a post as
 * context (lifetime, fields, notice text, purge URLs) are applied by the class
 * that has that context; this class only supplies the base value.
 */
final class Config
{
    public const PREFIX = 'COMMENTS_THROUGH_CACHE_';

    public const LIFETIME_DEFAULT = 86400;
    public const LIFETIME_MIN = 300;
    public const LIFETIME_MAX = 2592000;

    public const EARLY_EXPIRY_GRACE_DEFAULT = 300;

    public const FIELDS_EMAIL = 'email';
    public const FIELDS_ALL = 'all';

    public const KNOWN_PURGERS = ['action', 'http', 'file', 'cloudflare'];

    /** @var callable(string): mixed */
    private $reader;

    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * @param callable(string): mixed $reader Returns the value of a constant
     *                                        (name without prefix) or null.
     */
    public function __construct(callable $reader)
    {
        $this->reader = $reader;
    }

    /**
     * Reads real PHP constants such as COMMENTS_THROUGH_CACHE_DEBUG.
     */
    public static function fromConstants(): self
    {
        return new self(static function (string $name): mixed {
            $full = self::PREFIX . $name;
            return defined($full) ? constant($full) : null;
        });
    }

    /**
     * Builds a configuration from an array of values keyed by short name.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self(static fn (string $name): mixed => $values[$name] ?? null);
    }

    public function raw(string $name): mixed
    {
        if (!array_key_exists($name, $this->cache)) {
            $this->cache[$name] = ($this->reader)($name);
        }
        return $this->cache[$name];
    }

    public function debug(): bool
    {
        return self::toBool($this->raw('DEBUG'), false);
    }

    public function noticeEnabled(): bool
    {
        return self::toBool($this->raw('NOTICE'), true);
    }

    public function earlyExpiry(): bool
    {
        return self::toBool($this->raw('EARLY_EXPIRY'), true);
    }

    public function cookieLifetime(): int
    {
        $value = $this->raw('COOKIE_LIFETIME');
        if ($value === null || $value === '') {
            return self::LIFETIME_DEFAULT;
        }
        if (!is_numeric($value)) {
            throw new ConfigurationException('COOKIE_LIFETIME must be a whole number of seconds.');
        }
        return (int) $value;
    }

    /**
     * Clamps a cookie lifetime to the supported range.
     *
     * @return array{0: int, 1: bool} The clamped value and whether it changed.
     */
    public static function clampLifetime(int $seconds): array
    {
        $clamped = max(self::LIFETIME_MIN, min(self::LIFETIME_MAX, $seconds));
        return [$clamped, $clamped !== $seconds];
    }

    public function cookieFields(): string
    {
        $value = $this->raw('COOKIE_FIELDS');
        if ($value === null || $value === '') {
            return self::FIELDS_EMAIL;
        }
        return self::validateFields($value);
    }

    public static function validateFields(mixed $value): string
    {
        if ($value === self::FIELDS_EMAIL || $value === self::FIELDS_ALL) {
            return $value;
        }
        throw new ConfigurationException('COOKIE_FIELDS must be "email" or "all".');
    }

    /**
     * Configured purger names in the order given, unknown names removed.
     *
     * @return list<string>
     */
    public function purgerNames(): array
    {
        $names = [];
        foreach ($this->purgerList() as $name) {
            if (in_array($name, self::KNOWN_PURGERS, true) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names === [] ? ['action'] : $names;
    }

    /**
     * @return list<string>
     */
    private function purgerList(): array
    {
        $value = $this->raw('PURGERS');
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $value) as $part) {
            $part = strtolower(trim($part));
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    public function httpEndpoint(): ?string
    {
        return self::toNullableString($this->raw('HTTP_ENDPOINT'));
    }

    public function httpMethod(): string
    {
        $value = self::toNullableString($this->raw('HTTP_METHOD'));
        return $value === null ? 'PURGE' : strtoupper($value);
    }

    public function filePath(): ?string
    {
        return self::toNullableString($this->raw('FILE_PATH'));
    }

    public function fileLevels(): string
    {
        return self::toNullableString($this->raw('FILE_LEVELS')) ?? '1:2';
    }

    public function fileKey(): string
    {
        return self::toNullableString($this->raw('FILE_KEY')) ?? '{scheme}{method}{host}{uri}';
    }

    /**
     * @return list<string>
     */
    public function fileMethods(): array
    {
        $value = self::toNullableString($this->raw('FILE_METHODS')) ?? 'GET,HEAD';
        $out = [];
        foreach (explode(',', $value) as $part) {
            $part = strtoupper(trim($part));
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    public function cloudflareZoneId(): ?string
    {
        return self::toNullableString($this->raw('CF_ZONE_ID'));
    }

    public function cloudflareApiToken(): ?string
    {
        return self::toNullableString($this->raw('CF_API_TOKEN'));
    }

    public function httpTimeout(): int
    {
        return self::toIntInRange($this->raw('HTTP_TIMEOUT'), 3, 1, 30);
    }

    public function purgeBudget(): int
    {
        return self::toIntInRange($this->raw('PURGE_BUDGET'), 5, 1, 60);
    }

    /**
     * Problems that do not belong to one purger: unknown purger names and
     * values that cannot be clamped. Purger-specific problems come from each
     * purger's configurationProblems().
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach ($this->purgerList() as $name) {
            if (!in_array($name, self::KNOWN_PURGERS, true)) {
                $problems[] = sprintf('Unknown purger "%s" in PURGERS; known purgers are %s.', self::safeName($name), implode(', ', self::KNOWN_PURGERS));
            }
        }
        try {
            $this->cookieFields();
        } catch (ConfigurationException $e) {
            $problems[] = $e->getMessage();
        }
        try {
            $this->cookieLifetime();
        } catch (ConfigurationException $e) {
            $problems[] = $e->getMessage();
        }
        return $problems;
    }

    /**
     * The effective configuration for display. Secrets are never included:
     * the token is reported as set or not set.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        try {
            $lifetime = (string) self::clampLifetime($this->cookieLifetime())[0];
        } catch (ConfigurationException $e) {
            $lifetime = 'invalid';
        }
        try {
            $fields = $this->cookieFields();
        } catch (ConfigurationException $e) {
            $fields = 'invalid';
        }
        return [
            'cookie_lifetime' => $lifetime,
            'cookie_fields' => $fields,
            'notice' => $this->noticeEnabled() ? 'on' : 'off',
            'early_expiry' => $this->earlyExpiry() ? 'on' : 'off',
            'purgers' => implode(',', $this->purgerNames()),
            'http_endpoint' => self::maskUserInfo($this->httpEndpoint()) ?? 'not set',
            'http_method' => $this->httpMethod(),
            'file_path' => $this->filePath() ?? 'not set',
            'file_levels' => $this->fileLevels(),
            'file_key' => $this->fileKey(),
            'file_methods' => implode(',', $this->fileMethods()),
            'cf_zone_id' => $this->cloudflareZoneId() ?? 'not set',
            'cf_api_token' => $this->cloudflareApiToken() === null ? 'not set' : 'set',
            'http_timeout' => (string) $this->httpTimeout(),
            'purge_budget' => (string) $this->purgeBudget(),
            'debug' => $this->debug() ? 'on' : 'off',
        ];
    }

    public static function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return $default;
    }

    private static function toNullableString(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function toIntInRange(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    /**
     * Hides credentials an operator put in an endpoint URL
     * (http://user:pass@host/...), so the config command never prints them.
     */
    private static function maskUserInfo(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1***@', $url);
    }

    private static function safeName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/', '?', substr($name, 0, 32));
    }
}
