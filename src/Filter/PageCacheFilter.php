<?php

declare(strict_types=1);

namespace Switon\HttpCache\Filter;

use ReflectionMethod;
use Switon\Core\App;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\Attribute\EventListener;
use Switon\Core\AppInterface;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Core\StopFlow;
use Switon\HttpCache\Attribute\PageCache as PageCacheAttribute;
use Switon\Http\Event\RequestReady;
use Switon\Http\Event\HeadersSending;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Redis\ClientInterface;
use function explode;
use function http_build_query;
use function in_array;
use function is_array;
use function is_int;
use function ksort;
use function max;
use function md5;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Caches controller action responses and serves conditional HTTP responses from Redis.
 *
 * Use when actions marked with #[PageCache] reuse Redis cache and ETag 304.
 *
 * Road-signs:
 * - read/304 on RequestReady; write on HeadersSending
 * - PageCache attribute + Redis
 *
 * @see \Switon\HttpCache\Attribute\PageCache
 */
class PageCacheFilter implements ContextAware
{
    #[Autowired] protected AppInterface $app;

    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;
    #[Autowired] protected ClientInterface $redisCache;

    protected string $prefix;

    /** @var array<string, PageCacheAttribute|false> Per-handler cache attribute lookup table. */
    protected array $pageCaches = [];

    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct(?string $prefix = null)
    {
        $this->prefix = $prefix ?? sprintf('cache:%s:page_cache:', $this->app->id());
    }

    public function getContext(): PageCacheFilterContext
    {
        return $this->contextManager->getContext($this);
    }

    protected function getPageCache(ReflectionMethod $rMethod): PageCacheAttribute|false
    {
        if (($attributes = $rMethod->getAttributes(PageCacheAttribute::class)) !== []) {
            /** @noinspection PhpIncompatibleReturnTypeInspection */
            return $attributes[0]->newInstance();
        } else {
            return false;
        }
    }

    protected function formatEtag(string $content): string
    {
        return '"' . md5($content) . '"';
    }

    protected function normalizeEtag(?string $etag): string
    {
        $etag = trim((string)$etag);
        if ($etag === '') {
            return '';
        }

        if (str_starts_with($etag, 'W/')) {
            $etag = trim(substr($etag, 2));
        }

        return '"' . trim($etag, '"') . '"';
    }

    protected function ifNoneMatchContains(string $ifNoneMatch, string $etag): bool
    {
        $etag = $this->normalizeEtag($etag);
        if ($etag === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }

            if ($this->normalizeEtag($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    #[EventListener] public function onReady(RequestReady $event): void
    {
        if (!in_array($this->request->verb(), ['GET', 'POST', 'HEAD'], true)) {
            return;
        }

        $controller = $event->controller;
        $action = $event->action;

        $key = $controller . '::' . $action;
        if (($pageCache = $this->pageCaches[$key] ?? null) === null) {
            $pageCache = $this->pageCaches[$key] = $this->getPageCache($event->method);
        }

        if ($pageCache === false) {
            return;
        }

        $context = $this->getContext();

        $context->ttl = $pageCache->ttl;

        $key = null;
        if ($pageCache->key !== null) {
            $key = $pageCache->key;
            if (is_array($key)) {
                $params = [];
                foreach ((array)$pageCache->key as $k => $v) {
                    if (is_int($k)) {
                        $param_name = $v;
                        $param_value = $this->request->get($param_name, '');
                    } else {
                        $param_name = $k;
                        $param_value = $v;
                    }

                    if ($param_value !== '') {
                        $params[$param_name] = $param_value;
                    }
                }

                ksort($params);
                $key = http_build_query($params);
            }
        }

        if ($key === null) {
            $params = [];
            foreach ($this->request->all() as $name => $value) {
                if ($value !== '') {
                    $params[$name] = $value;
                }
            }

            ksort($params);
            $key = http_build_query($params);
        }

        $handler = $controller . '::' . $action;
        if ($key === '') {
            $context->key = $this->prefix . $handler;
        } else {
            $context->key = $this->prefix . $handler . ':' . $key;
        }

        $accept = $this->request->header('accept');
        if ($accept !== null && str_contains($accept, 'application/json')) {
            $context->key .= ':json';
        }

        $context->if_none_match = $this->request->header('if-none-match') ?? '';

        if (($etag = $this->redisCache->hGet($context->key, 'etag')) === false) {
            return;
        }

        if ($this->ifNoneMatchContains($context->if_none_match, (string)$etag)) {
            $this->response->setStatus(304, 'Not Modified');
            $this->response->setContent('');
            throw StopFlow::because('Not Modified - ETag match');
        }

        if (!$cache = $this->redisCache->hGetAll($context->key)) {
            return;
        }

        $this->response->setHeader('ETag', $this->normalizeEtag($cache['etag'] ?? ''));

        $ttl = max($this->redisCache->ttl($context->key), 1);
        $this->response->setHeader('Cache-Control', "max-age=$ttl");
        $this->response->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');

        $contentType = (string)($cache['content-type'] ?? 'text/plain');

        $acceptEncoding = $this->request->header('accept-encoding') ?? '';
        if (str_contains($acceptEncoding, 'gzip')) {
            $this->response->setHeader('Content-Encoding', 'gzip');
            $this->response->raw($cache['content'], $contentType);
        } else {
            $this->response->raw(gzdecode($cache['content']), $contentType);
        }
        $context->cache_used = true;

        throw StopFlow::because('Page cache hit');
    }

    /** @noinspection PhpUnusedParameterInspection */
    #[EventListener] public function onResponseHeadersSending(HeadersSending $event): void
    {
        $context = $this->getContext();

        if ($context->cache_used === true || $context->ttl === null || $context->ttl <= 0) {
            return;
        }

        if ($this->response->getStatusCode() !== 200) {
            return;
        }

        $content = $this->response->getContent() ?? '';
        $etag = $this->formatEtag($content);

        $this->redisCache->hMSet(
            $context->key,
            [
                'ttl' => $context->ttl,
                'etag' => $etag,
                'content-type' => $this->response->getHeader('Content-Type'),
                'content' => gzencode($content)
            ]
        );
        $this->redisCache->expire($context->key, $context->ttl);

        if ($this->ifNoneMatchContains($context->if_none_match, $etag)) {
            $this->response->setStatus(304, 'Not Modified');
            $this->response->setContent('');
        } else {
            $this->response->setHeader('Cache-Control', "max-age=$context->ttl");
            $this->response->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $context->ttl) . ' GMT');
            $this->response->setHeader('ETag', $etag);
        }
    }
}
