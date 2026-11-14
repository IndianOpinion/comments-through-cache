<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Fakes;

use IndianOpinion\CommentsThroughCache\Http\HttpResponse;

/**
 * A WordPress site in debug mode behind a page cache whose rules can be
 * broken one at a time, for the checker tests.
 */
final class FakeCachedSite
{
    public bool $debug = true;
    public bool $ignoreCookie = false;
    public bool $storeRedirect = false;
    public bool $storeSetCookie = false;
    public bool $sendNoStore = true;
    public bool $setCookieOnPlain = false;

    private int $renders = 0;

    /** @var array<string, HttpResponse> */
    private array $store = [];

    public function client(): FakeHttpClient
    {
        return new FakeHttpClient(fn (string $method, string $url, array $headers): HttpResponse => $this->handle($url, $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function handle(string $url, array $headers): HttpResponse
    {
        $cookie = $headers['Cookie'] ?? '';
        $redirect = (bool) preg_match('/[?&](unapproved|moderation-hash)=/', $url);
        $bypass = (!$this->ignoreCookie && str_contains($cookie, 'comment_author_'))
            || (!$this->storeRedirect && $redirect);

        if (!$bypass && isset($this->store[$url])) {
            return $this->store[$url];
        }
        $response = $this->render($redirect);
        $cacheable = $this->storeSetCookie || $response->headerValues('set-cookie') === [];
        if (!$bypass && $cacheable) {
            $this->store[$url] = $response;
        }
        return $response;
    }

    private function render(bool $redirect): HttpResponse
    {
        $this->renders++;
        $headers = [];
        if ($this->debug) {
            $headers['X-Comments-Through-Cache-Render'] = 'render-' . $this->renders;
        }
        if ($redirect && $this->sendNoStore) {
            $headers['Cache-Control'] = 'private, no-store, max-age=0';
        }
        if ($this->setCookieOnPlain && !$redirect) {
            $headers['Set-Cookie'] = 'tracker=1; path=/';
        }
        return new HttpResponse(200, $headers, '<html></html>');
    }
}
