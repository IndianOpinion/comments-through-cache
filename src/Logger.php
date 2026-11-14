<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Writes one line per event to PHP's error log, prefixed with the plugin name.
 *
 * Callers must never pass personal data: no cookie values, email addresses,
 * comment content or tokens. Error lines are always written; debug lines only
 * when debug mode is on.
 */
final class Logger
{
    public const PREFIX = '[comments-through-cache] ';

    /** @var callable(string): mixed */
    private $sink;

    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @param callable(string): mixed|null $sink Receives each finished line.
     */
    public function __construct(private bool $debug = false, ?callable $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function error(string $message): void
    {
        ($this->sink)(self::PREFIX . $message);
    }

    public function debug(string $message): void
    {
        if ($this->debug) {
            ($this->sink)(self::PREFIX . $message);
        }
    }

    /**
     * Writes an error line at most once per request for the given key.
     */
    public function errorOnce(string $key, string $message): void
    {
        if (isset($this->seen['e:' . $key])) {
            return;
        }
        $this->seen['e:' . $key] = true;
        $this->error($message);
    }

    /**
     * Writes a debug line at most once per request for the given key.
     */
    public function debugOnce(string $key, string $message): void
    {
        if (!$this->debug || isset($this->seen['d:' . $key])) {
            return;
        }
        $this->seen['d:' . $key] = true;
        $this->debug($message);
    }
}
