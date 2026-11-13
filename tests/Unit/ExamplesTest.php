<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class ExamplesTest extends TestCase
{
    private static function read(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . $file);
    }

    public function testT13NginxBypassesOnCookieAndRedirect(): void
    {
        $nginx = self::read('nginx-fastcgi-cache.conf');
        $this->assertStringContainsString('~*comment_author_       1;', $nginx);
        $this->assertStringContainsString('(unapproved|moderation-hash)=', $nginx);
        $this->assertStringContainsString('fastcgi_cache_bypass   $ctc_skip_cookie $ctc_skip_args $ctc_skip_method;', $nginx);
        $this->assertStringContainsString('fastcgi_no_cache       $ctc_skip_cookie $ctc_skip_args $ctc_skip_method;', $nginx);
    }

    public function testT13VarnishBypassesOnCookieAndRedirect(): void
    {
        $vcl = self::read('varnish.vcl');
        $this->assertStringContainsString('comment_author_', $vcl);
        $this->assertStringContainsString('(unapproved|moderation-hash)=', $vcl);
    }

    public function testT13CloudflareRuleExpression(): void
    {
        $rule = self::read('cloudflare-cache-rule.txt');
        $this->assertStringContainsString('(http.cookie contains "comment_author_")', $rule);
        $this->assertStringContainsString('(http.request.uri.query contains "unapproved=")', $rule);
        $this->assertStringContainsString('(http.request.uri.query contains "moderation-hash=")', $rule);
        $this->assertStringContainsString('Bypass cache', $rule);
    }

    public function testT16OnlyGetAndHeadAreCacheable(): void
    {
        $nginx = self::read('nginx-fastcgi-cache.conf');
        $this->assertMatchesRegularExpression('/map \$request_method \$ctc_skip_method \{\s+default 1;\s+GET\s+0;\s+HEAD\s+0;\s+\}/', $nginx);
        $this->assertStringContainsString('fastcgi_cache_methods  GET HEAD;', $nginx);
        $vcl = self::read('varnish.vcl');
        $this->assertStringContainsString('if (req.method != "GET" && req.method != "HEAD") {', $vcl);
    }

    public function testRulingFiveNginxKeyIncludesTheMethodAndMatchesTheFilePurger(): void
    {
        $nginx = self::read('nginx-fastcgi-cache.conf');
        $this->assertStringContainsString('fastcgi_cache_key "$scheme$request_method$host$request_uri";', $nginx);
    }

    public function testC15ExamplesNeverStoreSetCookie(): void
    {
        $nginx = self::read('nginx-fastcgi-cache.conf');
        $this->assertDoesNotMatchRegularExpression('/^\s*#?\s*fastcgi_ignore_headers[^\n]*Set-Cookie/m', $nginx);
        $this->assertStringContainsString('if (beresp.http.Set-Cookie) {', self::read('varnish.vcl'));
    }

    public function testMuPluginLoaderRequiresThePluginFile(): void
    {
        $this->assertStringContainsString(
            "require_once __DIR__ . '/comments-through-cache/comments-through-cache.php';",
            self::read('mu-plugin-loader.php')
        );
    }

    public function testPhpExamplesAreValidSyntax(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/examples/*.php') ?: [] as $file) {
            $tokens = token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            $this->assertNotSame([], $tokens, basename($file));
        }
    }
}
