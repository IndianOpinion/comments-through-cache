<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * The checker's result: one line per check and an exit code.
 */
final class CheckReport
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const INFO = 'info';

    /** @var list<array{status: string, check: string, message: string}> */
    private array $lines = [];

    public function add(string $status, string $check, string $message): void
    {
        $this->lines[] = ['status' => $status, 'check' => $check, 'message' => $message];
    }

    /**
     * @return list<array{status: string, check: string, message: string}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string> Lines formatted for a terminal.
     */
    public function formatted(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $out[] = sprintf('%-4s %-9s %s', strtoupper($line['status']), $line['check'], $line['message']);
        }
        return $out;
    }

    public function exitCode(): int
    {
        foreach ($this->lines as $line) {
            if ($line['status'] === self::FAIL) {
                return 1;
            }
        }
        return 0;
    }
}
