<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Http;

/**
 * An HTTP response: status, headers (lower-case names) and body.
 */
final class HttpResponse
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(private int $status, array $headers = [], private string $body = '')
    {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value) ? array_values($value) : [$value];
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * The first value of a header, or null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * Every value of a header, in order.
     *
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
