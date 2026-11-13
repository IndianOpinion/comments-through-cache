<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * The outcome of one purger run on one purge set.
 */
final class PurgeResult
{
    /**
     * @param list<string> $attempted
     * @param list<string> $succeeded
     * @param list<string> $errors    Plain messages without personal data or secrets.
     */
    public function __construct(
        private string $purger,
        private array $attempted,
        private array $succeeded,
        private array $errors = []
    ) {
    }

    /**
     * @param list<string> $urls
     */
    public static function failure(string $purger, array $urls, string $error): self
    {
        return new self($purger, $urls, [], [$error]);
    }

    public function purger(): string
    {
        return $this->purger;
    }

    /**
     * @return list<string>
     */
    public function attempted(): array
    {
        return $this->attempted;
    }

    /**
     * @return list<string>
     */
    public function succeeded(): array
    {
        return $this->succeeded;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function isSuccess(): bool
    {
        return $this->errors === [] && count($this->succeeded) === count($this->attempted);
    }

    public function summary(): string
    {
        return sprintf(
            '%s: %d of %d URLs purged%s',
            $this->purger,
            count($this->succeeded),
            count($this->attempted),
            $this->errors === [] ? '' : '; ' . implode('; ', $this->errors)
        );
    }
}
