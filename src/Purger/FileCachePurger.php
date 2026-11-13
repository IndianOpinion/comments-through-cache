<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Purger;

use IndianOpinion\CommentsThroughCache\ConfigurationException;
use IndianOpinion\CommentsThroughCache\PurgeResult;

/**
 * Removes nginx FastCGI or proxy cache files directly, for a cache with no
 * purge module.
 *
 * nginx names a cache file after the MD5 of its cache key and places it in
 * subdirectories taken from the end of that hash, per the "levels" setting.
 * With levels=1:2 and hash b7f54b2df7773722d382f4809d65029c the file is
 * <path>/c/29/b7f54b2df7773722d382f4809d65029c. This purger rebuilds the key
 * from a template for every configured method, computes the file name, and
 * removes the file if it is a regular file inside the cache directory.
 */
final class FileCachePurger implements Purger, Configurable
{
    /**
     * @param list<string> $methods
     */
    public function __construct(
        private ?string $path,
        private string $levels = '1:2',
        private string $keyTemplate = '{scheme}{method}{host}{uri}',
        private array $methods = ['GET', 'HEAD']
    ) {
    }

    public function configurationProblems(): array
    {
        $problems = [];
        if ($this->path === null || $this->path === '') {
            $problems[] = 'The file purger needs COMMENTS_THROUGH_CACHE_FILE_PATH.';
        } elseif (!str_starts_with($this->path, '/')) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_FILE_PATH must be an absolute path.';
        } elseif (!is_dir($this->path) || realpath($this->path) === false) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_FILE_PATH is not a readable directory.';
        }
        if (self::parseLevels($this->levels) === null) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_FILE_LEVELS must look like 1:2 (one to three levels of 1 or 2).';
        }
        if ($this->methods === []) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_FILE_METHODS must list at least one method.';
        }
        if (!str_contains($this->keyTemplate, '{uri}')) {
            $problems[] = 'COMMENTS_THROUGH_CACHE_FILE_KEY must contain {uri}.';
        }
        return $problems;
    }

    public function purge(array $urls, string $reason = 'manual'): PurgeResult
    {
        $problems = $this->configurationProblems();
        if ($problems !== []) {
            throw new ConfigurationException(implode(' ', $problems));
        }
        $base = (string) realpath((string) $this->path);
        $succeeded = [];
        $errors = [];
        foreach ($urls as $url) {
            $ok = true;
            foreach ($this->methods as $method) {
                $file = $this->fileFor($url, $method);
                if ($file === null) {
                    $errors[] = 'skipped an unparsable URL';
                    $ok = false;
                    break;
                }
                $error = self::removeInside($base, $file);
                if ($error !== null) {
                    $errors[] = $error;
                    $ok = false;
                }
            }
            if ($ok) {
                $succeeded[] = $url;
            }
        }
        return new PurgeResult($this->name(), $urls, $succeeded, $errors);
    }

    /**
     * The nginx cache key for a URL and method.
     */
    public function keyFor(string $url, string $method): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $uri = (isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/')
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        return strtr($this->keyTemplate, [
            '{scheme}' => (string) ($parts['scheme'] ?? 'http'),
            '{method}' => $method,
            '{host}' => $parts['host'],
            '{uri}' => $uri,
        ]);
    }

    /**
     * The cache file path for a URL and method, or null if the URL is bad.
     */
    public function fileFor(string $url, string $method): ?string
    {
        $key = $this->keyFor($url, $method);
        $levels = self::parseLevels($this->levels);
        if ($key === null || $levels === null || $this->path === null) {
            return null;
        }
        $hash = md5($key);
        $dir = rtrim($this->path, '/');
        $offset = strlen($hash);
        foreach ($levels as $width) {
            $offset -= $width;
            $dir .= '/' . substr($hash, $offset, $width);
        }
        return $dir . '/' . $hash;
    }

    /**
     * @return list<int>|null
     */
    public static function parseLevels(string $levels): ?array
    {
        if (preg_match('/^[12](:[12]){0,2}$/', $levels) !== 1) {
            return null;
        }
        return array_map('intval', explode(':', $levels));
    }

    /**
     * Removes $file if it is a regular file inside $base. Returns an error
     * message, or null on success (including "not cached").
     */
    private static function removeInside(string $base, string $file): ?string
    {
        if (is_link($file)) {
            return 'refused to remove a symbolic link in the cache directory';
        }
        if (!file_exists($file)) {
            return null; // Not cached: nothing to do.
        }
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, rtrim($base, '/') . '/')) {
            return 'refused to remove a file outside the cache directory';
        }
        if (!is_file($real)) {
            return 'refused to remove something that is not a regular file';
        }
        $warning = null;
        set_error_handler(static function (int $no, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $removed = unlink($real);
        } finally {
            restore_error_handler();
        }
        if (!$removed) {
            return 'could not remove a cache file' . ($warning === null ? '' : ': ' . self::stripPath($warning));
        }
        return null;
    }

    private static function stripPath(string $message): string
    {
        return (string) preg_replace('#/[^\s:]+#', '<path>', $message);
    }

    public function layer(): string
    {
        return self::LAYER_ORIGIN;
    }

    public function name(): string
    {
        return 'file';
    }
}
