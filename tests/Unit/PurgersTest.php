<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Tests\Unit;

use IndianOpinion\CommentsThroughCache\ConfigurationException;
use IndianOpinion\CommentsThroughCache\Http\HttpResponse;
use IndianOpinion\CommentsThroughCache\Purger\ActionPurger;
use IndianOpinion\CommentsThroughCache\Purger\CloudflarePurger;
use IndianOpinion\CommentsThroughCache\Purger\FileCachePurger;
use IndianOpinion\CommentsThroughCache\Purger\HttpPurger;
use IndianOpinion\CommentsThroughCache\Tests\Fakes\FakeHttpClient;
use IndianOpinion\CommentsThroughCache\Tests\TestCase;

final class PurgersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ctc-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);
        parent::tearDown();
    }

    private function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rmTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function place(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, 'cached');
    }

    // T44: FileCachePurger.

    public function testT44KeyAndLevelsGiveTheDocumentedFileName(): void
    {
        $purger = new FileCachePurger($this->dir . '/cache');
        $url = 'https://example.com/hello-world/';
        $this->assertSame('httpsGETexample.com/hello-world/', $purger->keyFor($url, 'GET'));
        $this->assertSame('httpsHEADexample.com/hello-world/', $purger->keyFor($url, 'HEAD'));

        $hash = md5('httpsGETexample.com/hello-world/');
        $this->assertSame(
            $this->dir . '/cache/' . substr($hash, -1) . '/' . substr($hash, -3, 2) . '/' . $hash,
            $purger->fileFor($url, 'GET')
        );
        $this->assertSame('httpsGETexample.com/a/?cpage=2', $purger->keyFor('https://example.com/a/?cpage=2', 'GET'));
    }

    public function testT44LevelsAreTakenFromTheEndOfTheHash(): void
    {
        // nginx docs: with levels=1:2, hash b7f54b2df7773722d382f4809d65029c
        // lives at c/29/b7f54b2df7773722d382f4809d65029c. Same rule, three levels.
        $purger = new FileCachePurger('/data/nginx/cache', '2:2:1', '{uri}');
        $hash = md5('/x/');
        $this->assertSame(
            '/data/nginx/cache/' . substr($hash, -2) . '/' . substr($hash, -4, 2) . '/' . substr($hash, -5, 1) . '/' . $hash,
            $purger->fileFor('https://example.com/x/', 'GET')
        );
        $this->assertSame([1, 2], FileCachePurger::parseLevels('1:2'));
        $this->assertNull(FileCachePurger::parseLevels('3:2'));
        $this->assertNull(FileCachePurger::parseLevels('1:2:2:2'));
    }

    public function testT44RemovesGetAndHeadFiles(): void
    {
        $purger = new FileCachePurger($this->dir . '/cache');
        $url = 'https://example.com/hello-world/';
        $get = (string) $purger->fileFor($url, 'GET');
        $head = (string) $purger->fileFor($url, 'HEAD');
        $this->place($get);
        $this->place($head);

        $result = $purger->purge([$url, 'https://example.com/not-cached/']);
        $this->assertTrue($result->isSuccess());
        $this->assertFileDoesNotExist($get);
        $this->assertFileDoesNotExist($head);
    }

    public function testT44RefusesASymlink(): void
    {
        $purger = new FileCachePurger($this->dir . '/cache');
        $url = 'https://example.com/a/';
        $file = (string) $purger->fileFor($url, 'GET');
        mkdir(dirname($file), 0777, true);
        file_put_contents($this->dir . '/precious', 'keep');
        symlink($this->dir . '/precious', $file);

        $result = $purger->purge([$url]);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('symbolic link', $result->errors()[0]);
        $this->assertFileExists($this->dir . '/precious');
    }

    public function testT44RefusesAPathOutsideTheDirectory(): void
    {
        // A level directory that is a symlink out of the cache directory.
        $purger = new FileCachePurger($this->dir . '/cache');
        $url = 'https://example.com/b/';
        $file = (string) $purger->fileFor($url, 'GET');
        $levelOne = dirname($file, 2);
        mkdir($this->dir . '/outside/' . basename(dirname($file)), 0777, true);
        symlink($this->dir . '/outside', $levelOne);
        file_put_contents($this->dir . '/outside/' . basename(dirname($file)) . '/' . basename($file), 'keep');

        $result = $purger->purge([$url]);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('outside the cache directory', $result->errors()[0]);
        $this->assertFileExists($this->dir . '/outside/' . basename(dirname($file)) . '/' . basename($file));
    }

    public function testT44RefusesADirectory(): void
    {
        $purger = new FileCachePurger($this->dir . '/cache', '1:2', '{scheme}{method}{host}{uri}', ['GET']);
        $url = 'https://example.com/c/';
        mkdir((string) $purger->fileFor($url, 'GET'), 0777, true);
        $result = $purger->purge([$url]);
        $this->assertStringContainsString('not a regular file', $result->errors()[0]);
    }

    public function testFilePurgerConfigurationProblems(): void
    {
        $this->assertNotSame([], (new FileCachePurger(null))->configurationProblems());
        $this->assertNotSame([], (new FileCachePurger('relative/path'))->configurationProblems());
        $this->assertNotSame([], (new FileCachePurger($this->dir . '/missing'))->configurationProblems());
        $this->assertNotSame([], (new FileCachePurger($this->dir . '/cache', '9'))->configurationProblems());
        $this->assertNotSame([], (new FileCachePurger($this->dir . '/cache', '1:2', '{host}'))->configurationProblems());
        $this->assertSame([], (new FileCachePurger($this->dir . '/cache'))->configurationProblems());
        $this->expectException(ConfigurationException::class);
        (new FileCachePurger(null))->purge(['https://example.com/']);
    }

    // T45: CloudflarePurger.

    public function testT45BatchesOfThirtyWithBearerToken(): void
    {
        $client = new FakeHttpClient(static fn (): HttpResponse => new HttpResponse(200, [], '{"success":true,"errors":[],"result":{"id":"x"}}'));
        $zone = str_repeat('0', 31) . 'f';
        $purger = new CloudflarePurger($client, $zone, 'test-token-value');
        $urls = [];
        for ($i = 0; $i < 65; $i++) {
            $urls[] = 'https://example.com/p' . $i . '/';
        }
        $result = $purger->purge($urls);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(3, $client->requests);
        $sizes = [];
        foreach ($client->requests as $request) {
            $this->assertSame('POST', $request['method']);
            $this->assertSame('https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache', $request['url']);
            $this->assertSame('Bearer test-token-value', $request['headers']['Authorization']);
            $body = json_decode((string) $request['body'], true);
            $sizes[] = count($body['files']);
        }
        $this->assertSame([30, 30, 5], $sizes);
        $this->assertSame('https://example.com/p0/', json_decode((string) $client->requests[0]['body'], true)['files'][0]);
    }

    public function testT45ResponseWithoutSuccessIsFailure(): void
    {
        $bodies = ['{"success":false,"errors":[{"code":1012,"message":"Request must contain one of files"}]}', 'not json', '{"result":{}}'];
        $client = new FakeHttpClient(static function () use (&$bodies): HttpResponse {
            return new HttpResponse(200, [], (string) array_shift($bodies));
        });
        add_filter('comments_through_cache_cloudflare_batch_size', static fn (): int => 1);
        $purger = new CloudflarePurger($client, str_repeat('a', 32), 'tok');
        $result = $purger->purge(['https://example.com/1', 'https://example.com/2', 'https://example.com/3']);
        $this->assertSame([], $result->succeeded());
        $this->assertCount(3, $result->errors());
        $this->assertStringContainsString('error 1012', $result->errors()[0]);
    }

    public function testCloudflareConfiguration(): void
    {
        $client = new FakeHttpClient();
        $this->assertCount(2, (new CloudflarePurger($client, null, null))->configurationProblems());
        $this->assertCount(1, (new CloudflarePurger($client, 'zone', 'tok'))->configurationProblems());
        $this->assertCount(1, (new CloudflarePurger($client, str_repeat('a', 32), "bad token\n"))->configurationProblems());
        $this->assertSame('edge', (new CloudflarePurger($client, null, null))->layer());
    }

    public function testCloudflareBatchSizeIsClamped(): void
    {
        $purger = new CloudflarePurger(new FakeHttpClient(), null, null);
        $this->assertSame(30, $purger->batchSize());
        add_filter('comments_through_cache_cloudflare_batch_size', static fn (): int => 5000);
        $this->assertSame(100, $purger->batchSize());
    }

    // T46: HttpPurger.

    public function testT46EndpointTemplateMethodAndHost(): void
    {
        $client = new FakeHttpClient(static fn (): HttpResponse => new HttpResponse(404));
        $purger = new HttpPurger($client, 'http://127.0.0.1:8080/purge{path}{query}', 'PURGE', 3);
        $result = $purger->purge(['https://example.com/hello/', 'https://example.com/?p=3&cpage=2']);

        $this->assertTrue($result->isSuccess(), '404 means not cached, which is the goal');
        $this->assertSame('PURGE', $client->requests[0]['method']);
        $this->assertSame('http://127.0.0.1:8080/purge/hello/', $client->requests[0]['url']);
        $this->assertSame(['Host' => 'example.com'], $client->requests[0]['headers']);
        $this->assertSame('http://127.0.0.1:8080/purge/?p=3&cpage=2', $client->requests[1]['url']);
        $this->assertSame(3, $client->requests[0]['timeout']);
    }

    public function testT46GetMethodAndAllTokens(): void
    {
        $client = new FakeHttpClient();
        (new HttpPurger($client, 'http://varnish.internal/{scheme}/{host}{path}', 'GET'))->purge(['https://example.com/x/']);
        $this->assertSame('GET', $client->requests[0]['method']);
        $this->assertSame('http://varnish.internal/https/example.com/x/', $client->requests[0]['url']);
    }

    public function testHttpPurgerConfiguration(): void
    {
        $client = new FakeHttpClient();
        $this->assertCount(1, (new HttpPurger($client, null))->configurationProblems());
        $this->assertCount(1, (new HttpPurger($client, 'file:///etc/passwd'))->configurationProblems());
        $this->assertCount(1, (new HttpPurger($client, 'http://127.0.0.1/', 'DELETE'))->configurationProblems());
        $this->expectException(ConfigurationException::class);
        (new HttpPurger($client, null))->purge(['https://example.com/']);
    }

    public function testActionPurgerReportsListeners(): void
    {
        $this->assertFalse(ActionPurger::hasListener());
        add_action('comments_through_cache_purge', static function (): void {
        }, 10, 2);
        $this->assertTrue(ActionPurger::hasListener());
        $result = (new ActionPurger())->purge(['https://example.com/a/'], 'manual');
        $this->assertTrue($result->isSuccess());
        $this->assertSame('origin', (new ActionPurger())->layer());
    }
}
