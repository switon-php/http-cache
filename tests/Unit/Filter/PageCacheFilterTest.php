<?php

declare(strict_types=1);

namespace Switon\HttpCache\Tests\Unit\Filter;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use ReflectionMethod;
use Switon\Core\ContextManagerInterface;
use Switon\Core\StopFlow;
use Switon\Http\Event\HeadersSending;
use Switon\Http\Event\RequestReady;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\HttpCache\Attribute\PageCache;
use Switon\HttpCache\Filter\PageCacheFilter;
use Switon\HttpCache\Filter\PageCacheFilterContext;
use Switon\HttpCache\Tests\TestCase;
use Switon\Redis\ClientInterface;

use function gzencode;
use function md5;
use function str_ends_with;
use function strlen;

final class PageCacheFilterControllerStub
{
    #[PageCache(ttl: 120)]
    public function withDefaultKey(): void
    {
    }

    #[PageCache(ttl: 60, key: ['uid', 'lang' => 'zh-CN'])]
    public function withArrayKey(): void
    {
    }

    public function withoutCache(): void
    {
    }
}

class PageCacheRedisClientDouble implements ClientInterface
{
    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        return $this;
    }

    public function hGet(string $key, string $member): mixed
    {
        return false;
    }

    public function hGetAll(string $key): array|false
    {
        return false;
    }

    public function ttl(string $key): int|false
    {
        return false;
    }

    public function hMSet(string $key, array $pairs): bool
    {
        return true;
    }

    public function expire(string $key, int $ttl): bool
    {
        return true;
    }
}

final class TestablePageCacheFilter extends PageCacheFilter
{
    public function inject(
        ContextManagerInterface $contextManager,
        RequestInterface        $request,
        ResponseInterface       $response,
        ClientInterface         $redisCache
    ): void {
        $this->contextManager = $contextManager;
        $this->request = $request;
        $this->response = $response;
        $this->redisCache = $redisCache;
    }

    public function normalizeEtagPublic(?string $etag): string
    {
        return $this->normalizeEtag($etag);
    }

    public function ifNoneMatchContainsPublic(string $ifNoneMatch, string $etag): bool
    {
        return $this->ifNoneMatchContains($ifNoneMatch, $etag);
    }
}

#[AllowMockObjectsWithoutExpectations]
class PageCacheFilterTest extends TestCase
{
    public function testNormalizeEtagNormalizesWeakAndUnquotedEtags(): void
    {
        $filter = new TestablePageCacheFilter('cache:test:');

        $this->assertSame('"abc"', $filter->normalizeEtagPublic('abc'));
        $this->assertSame('"abc"', $filter->normalizeEtagPublic('"abc"'));
        $this->assertSame('"abc"', $filter->normalizeEtagPublic(' W/"abc" '));
        $this->assertSame('', $filter->normalizeEtagPublic(null));
        $this->assertSame('', $filter->normalizeEtagPublic('   '));
    }

    public function testIfNoneMatchContainsSupportsWildcardAndWeakEtags(): void
    {
        $filter = new TestablePageCacheFilter('cache:test:');

        $this->assertTrue($filter->ifNoneMatchContainsPublic('*', '"x"'));
        $this->assertTrue($filter->ifNoneMatchContainsPublic('W/"abc"', '"abc"'));
        $this->assertTrue($filter->ifNoneMatchContainsPublic('"a", W/"abc" , "b"', 'abc'));
        $this->assertFalse($filter->ifNoneMatchContainsPublic('"a", "b"', '"abc"'));
        $this->assertFalse($filter->ifNoneMatchContainsPublic('', '"abc"'));
    }

    public function testOnReadyReturnsEarlyForUnsupportedVerb(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $request->expects($this->once())->method('verb')->willReturn('DELETE');
        $contextManager->expects($this->never())->method('getContext');
        $redis->expects($this->never())->method('hGet');

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject(
            $contextManager,
            $request,
            $response,
            $redis
        );
        $event = $this->createReadyEvent('withArrayKey');

        $filter->onReady($event);

        $this->assertNull($context->ttl);
    }

    public function testOnReadyReturnsWhenHandlerHasNoPageCacheAttribute(): void
    {
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $request->expects($this->once())->method('verb')->willReturn('GET');
        $contextManager->expects($this->never())->method('getContext');
        $redis->expects($this->never())->method('hGet');

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject(
            $contextManager,
            $request,
            $response,
            $redis
        );
        $event = $this->createReadyEvent('withoutCache');
        $filter->onReady($event);
    }

    public function testOnReadyBuildsKeyFromAttributeArrayRule(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->expects($this->once())
            ->method('get')
            ->with('uid', '')
            ->willReturn('42');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => null,
                    'if-none-match' => null,
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->with(
                'cache:test:' . PageCacheFilterControllerStub::class . '::withArrayKey:lang=zh-CN&uid=42',
                'etag'
            )
            ->willReturn(false);

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject(
            $contextManager,
            $request,
            $response,
            $redis
        );
        $event = $this->createReadyEvent('withArrayKey');

        $filter->onReady($event);

        $this->assertSame(60, $context->ttl);
        $this->assertSame(
            'cache:test:' . PageCacheFilterControllerStub::class . '::withArrayKey:lang=zh-CN&uid=42',
            $context->key
        );
        $this->assertSame('', $context->if_none_match);
    }

    public function testOnReadyThrowsNotModifiedWhenEtagMatchesIfNoneMatch(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => null,
                    'if-none-match' => '"etag-1"',
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->with(
                'cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey',
                'etag'
            )
            ->willReturn('etag-1');
        $response->expects($this->once())
            ->method('setStatus')
            ->with(304, 'Not Modified')
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setContent')
            ->with('')
            ->willReturnSelf();

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject(
            $contextManager,
            $request,
            $response,
            $redis
        );
        $event = $this->createReadyEvent('withDefaultKey');

        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Not Modified - ETag match');
        $filter->onReady($event);
    }

    public function testOnReadyServesCachedBodyAndStopsFlow(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $headers = [];
        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => 'application/json',
                    'if-none-match' => '',
                    'accept-encoding' => '',
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey:json', 'etag')
            ->willReturn('etag-cache');
        $redis->expects($this->once())
            ->method('hGetAll')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey:json')
            ->willReturn([
                'etag' => 'etag-cache',
                'content-type' => 'text/html',
                'content' => gzencode('cached body'),
            ]);
        $redis->expects($this->once())
            ->method('ttl')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey:json')
            ->willReturn(60);
        $response->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                    $headers[$name] = $value;
                    return $response;
                }
            );
        $response->expects($this->once())
            ->method('raw')
            ->with('cached body', 'text/html')
            ->willReturnSelf();

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = $this->createReadyEvent('withDefaultKey');

        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Page cache hit');
        try {
            $filter->onReady($event);
        } finally {
            $this->assertTrue($context->cache_used);
            $this->assertSame('"etag-cache"', $headers['ETag'] ?? null);
            $this->assertSame('max-age=60', $headers['Cache-Control'] ?? null);
            $this->assertTrue(str_ends_with($headers['Expires'] ?? '', ' GMT'));
        }
    }

    public function testOnReadyServesCachedBodyWhenAcceptEncodingHeaderIsMissing(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $headers = [];
        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => null,
                    'if-none-match' => '',
                    'accept-encoding' => null,
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->with(
                'cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey',
                'etag'
            )
            ->willReturn('etag-missing-encoding');
        $redis->expects($this->once())
            ->method('hGetAll')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey')
            ->willReturn([
                'etag' => 'etag-missing-encoding',
                'content-type' => 'text/plain',
                'content' => gzencode('cached body without encoding header'),
            ]);
        $redis->expects($this->once())
            ->method('ttl')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey')
            ->willReturn(45);
        $response->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                    $headers[$name] = $value;
                    return $response;
                }
            );
        $response->expects($this->once())
            ->method('raw')
            ->with('cached body without encoding header', 'text/plain')
            ->willReturnSelf();

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = $this->createReadyEvent('withDefaultKey');

        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Page cache hit');
        try {
            $filter->onReady($event);
        } finally {
            $this->assertTrue($context->cache_used);
            $this->assertSame('"etag-missing-encoding"', $headers['ETag'] ?? null);
            $this->assertSame('max-age=45', $headers['Cache-Control'] ?? null);
            $this->assertArrayNotHasKey('Content-Encoding', $headers);
        }
    }

    public function testOnReadyUsesMaxAgeOneWhenRedisTtlIsUnavailable(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $headers = [];
        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => null,
                    'if-none-match' => '',
                    'accept-encoding' => '',
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->willReturn('etag-without-ttl');
        $redis->expects($this->once())
            ->method('hGetAll')
            ->willReturn([
                'etag' => 'etag-without-ttl',
                'content-type' => 'text/plain',
                'content' => gzencode('cached body'),
            ]);
        $redis->expects($this->once())
            ->method('ttl')
            ->willReturn(false);
        $response->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                    $headers[$name] = $value;
                    return $response;
                }
            );
        $response->expects($this->once())->method('raw')->with('cached body', 'text/plain')->willReturnSelf();

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = $this->createReadyEvent('withDefaultKey');

        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Page cache hit');
        try {
            $filter->onReady($event);
        } finally {
            $this->assertSame('max-age=1', $headers['Cache-Control'] ?? null);
            $this->assertTrue($context->cache_used);
        }
    }

    public function testOnReadyServesGzipBodyWhenClientAcceptsGzip(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $headers = [];
        $compressed = gzencode('cached gzip body');

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => null,
                    'if-none-match' => '',
                    'accept-encoding' => 'gzip,deflate',
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->with(
                'cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey',
                'etag'
            )
            ->willReturn('etag-gzip');
        $redis->expects($this->once())
            ->method('hGetAll')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey')
            ->willReturn([
                'etag' => 'etag-gzip',
                'content-type' => 'text/plain',
                'content' => $compressed,
            ]);
        $redis->expects($this->once())
            ->method('ttl')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey')
            ->willReturn(50);
        $response->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                    $headers[$name] = $value;
                    return $response;
                }
            );
        $response->expects($this->once())
            ->method('raw')
            ->with($compressed, 'text/plain')
            ->willReturnSelf();

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = $this->createReadyEvent('withDefaultKey');

        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Page cache hit');
        try {
            $filter->onReady($event);
        } finally {
            $this->assertSame('gzip', $headers['Content-Encoding'] ?? null);
            $this->assertSame('"etag-gzip"', $headers['ETag'] ?? null);
            $this->assertTrue($context->cache_used);
        }
    }

    public function testOnReadyPreservesCachedContentTypeExactly(): void
    {
        $context = new PageCacheFilterContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $headers = [];
        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $request->expects($this->once())->method('verb')->willReturn('GET');
        $request->method('header')
            ->willReturnCallback(
                static fn (string $name, ?string $default = null): ?string => match ($name) {
                    'accept' => null,
                    'if-none-match' => '',
                    'accept-encoding' => '',
                    default => $default
                }
            );
        $redis->expects($this->once())
            ->method('hGet')
            ->with(
                'cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey',
                'etag'
            )
            ->willReturn('etag-html-charset');
        $redis->expects($this->once())
            ->method('hGetAll')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey')
            ->willReturn([
                'etag' => 'etag-html-charset',
                'content-type' => 'text/html; charset=iso-8859-1',
                'content' => gzencode('cached html body'),
            ]);
        $redis->expects($this->once())
            ->method('ttl')
            ->with('cache:test:' . PageCacheFilterControllerStub::class . '::withDefaultKey')
            ->willReturn(40);
        $response->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                    $headers[$name] = $value;
                    return $response;
                }
            );
        $response->expects($this->once())
            ->method('raw')
            ->with('cached html body', 'text/html; charset=iso-8859-1')
            ->willReturnSelf();

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = $this->createReadyEvent('withDefaultKey');

        $this->expectException(StopFlow::class);
        $this->expectExceptionMessage('Page cache hit');
        try {
            $filter->onReady($event);
        } finally {
            $this->assertSame('"etag-html-charset"', $headers['ETag'] ?? null);
            $this->assertArrayNotHasKey('Content-Encoding', $headers);
            $this->assertTrue($context->cache_used);
        }
    }

    public function testOnResponseHeadersSendingReturnsEarlyWhenCacheAlreadyUsed(): void
    {
        $context = new PageCacheFilterContext();
        $context->ttl = 120;
        $context->cache_used = true;
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $response->expects($this->never())->method('getStatusCode');
        $redis->expects($this->never())->method('hMSet');
        $redis->expects($this->never())->method('expire');

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = new HeadersSending($response);
        $filter->onResponseHeadersSending($event);
    }

    public function testOnResponseHeadersSendingReturnsEarlyWhenTtlIsNull(): void
    {
        $context = new PageCacheFilterContext();
        $context->ttl = null;
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $response->expects($this->never())->method('getStatusCode');
        $redis->expects($this->never())->method('hMSet');

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = new HeadersSending($response);
        $filter->onResponseHeadersSending($event);
    }

    public function testOnResponseHeadersSendingReturnsEarlyWhenTtlIsZeroOrNegative(): void
    {
        foreach ([0, -1] as $ttl) {
            $context = new PageCacheFilterContext();
            $context->ttl = $ttl;
            $contextManager = $this->createMock(ContextManagerInterface::class);
            $request = $this->createMock(RequestInterface::class);
            $response = $this->createMock(ResponseInterface::class);
            $redis = $this->createMock(PageCacheRedisClientDouble::class);

            $contextManager->expects($this->once())
                ->method('getContext')
                ->willReturn($context);
            $response->expects($this->never())->method('getStatusCode');
            $redis->expects($this->never())->method('hMSet');

            $filter = new TestablePageCacheFilter('cache:test:');
            $filter->inject($contextManager, $request, $response, $redis);
            $event = new HeadersSending($response);
            $filter->onResponseHeadersSending($event);
        }
    }

    public function testOnResponseHeadersSendingReturnsEarlyWhenStatusIsNot200(): void
    {
        $context = new PageCacheFilterContext();
        $context->ttl = 120;
        $context->key = 'cache:test:not-200';
        $context->if_none_match = '';
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $response->expects($this->once())->method('getStatusCode')->willReturn(500);
        $response->expects($this->never())->method('getContent');
        $redis->expects($this->never())->method('hMSet');
        $redis->expects($this->never())->method('expire');

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = new HeadersSending($response);
        $filter->onResponseHeadersSending($event);
    }

    public function testOnResponseHeadersSendingWritesCacheAndResponseValidators(): void
    {
        $context = new PageCacheFilterContext();
        $context->ttl = 120;
        $context->key = 'cache:test:key';
        $context->if_none_match = 'outdated-etag';
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $headers = [];
        $body = 'fresh-content';
        $etag = '"' . md5($body) . '"';

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $response->expects($this->once())->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('getContent')->willReturn($body);
        $response->expects($this->once())
            ->method('getHeader')
            ->with('Content-Type')
            ->willReturn('text/html');
        $redis->expects($this->once())
            ->method('hMSet')
            ->with(
                'cache:test:key',
                [
                    'ttl' => 120,
                    'etag' => $etag,
                    'content-type' => 'text/html',
                    'content' => gzencode($body),
                ]
            )
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('expire')
            ->with('cache:test:key', 120)
            ->willReturn(true);
        $response->expects($this->never())->method('setStatus');
        $response->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value) use (&$headers, $response): ResponseInterface {
                    $headers[$name] = $value;
                    return $response;
                }
            );

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = new HeadersSending($response);
        $filter->onResponseHeadersSending($event);

        $this->assertSame("max-age={$context->ttl}", $headers['Cache-Control'] ?? null);
        $this->assertSame($etag, $headers['ETag'] ?? null);
        $this->assertTrue(str_ends_with($headers['Expires'] ?? '', ' GMT'));
        $this->assertGreaterThan(10, strlen($headers['Expires'] ?? ''));
    }

    public function testOnResponseHeadersSendingSets304WhenNewEtagMatchesIfNoneMatch(): void
    {
        $context = new PageCacheFilterContext();
        $context->ttl = 30;
        $context->key = 'cache:test:key304';
        $context->if_none_match = '"' . md5('same-content') . '"';
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $redis = $this->createMock(PageCacheRedisClientDouble::class);

        $contextManager->expects($this->once())
            ->method('getContext')
            ->willReturn($context);
        $response->expects($this->once())->method('getStatusCode')->willReturn(200);
        $response->expects($this->once())->method('getContent')->willReturn('same-content');
        $response->expects($this->once())
            ->method('getHeader')
            ->with('Content-Type')
            ->willReturn('text/plain');
        $redis->expects($this->once())->method('hMSet')->willReturn(true);
        $redis->expects($this->once())->method('expire')->with('cache:test:key304', 30)->willReturn(true);
        $response->expects($this->once())
            ->method('setStatus')
            ->with(304, 'Not Modified')
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setContent')
            ->with('')
            ->willReturnSelf();
        $response->expects($this->never())->method('setHeader');

        $filter = new TestablePageCacheFilter('cache:test:');
        $filter->inject($contextManager, $request, $response, $redis);
        $event = new HeadersSending($response);
        $filter->onResponseHeadersSending($event);
    }

    private function createReadyEvent(string $method): RequestReady
    {
        return new RequestReady(new ReflectionMethod(PageCacheFilterControllerStub::class, $method));
    }
}
