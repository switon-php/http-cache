# HTTP Cache Package {#http-cache-package}

`switon/http-cache` adds Redis-backed page caching for one controller action, with request-derived keys and `ETag`
revalidation.

**Key features:**

- **Action-level cache control:** `#[PageCache]` marks one action cacheable.
- **Request-derived keys:** cache keys can use request input or fixed fragments.
- **HTTP validation:** cached responses support `ETag` and `304 Not Modified`.
- **Redis storage:** page bodies are shared through `Switon\Redis\ClientInterface`.
- **Method boundaries:** lookup covers `GET`, `POST`, and `HEAD`; writes only happen for `200` responses.
- **Listener registration:** `extra.switon.listeners` makes `PageCacheFilter` discoverable through listener metadata,
  not through `RequestHandler.filters`.

---

## Installation {#installation}

**Requirements:** `PHP 8.3+`, `switon/http`, `switon/redis`, `switon/event`

```bash
composer require switon/http-cache
```

---

## Quick Start {#quick-start}

Use `#[PageCache]` on one action whose output is stable for the values you put in the key.

```php
namespace App\Controller;

use Switon\Http\RequestInterface;
use Switon\HttpCache\Attribute\PageCache;
use Switon\Routing\Attribute\GetMapping;

class ProductController
{
    #[GetMapping('/products')]
    #[PageCache(ttl: 600, key: ['category', 'page'])]
    public function listAction(RequestInterface $request): array
    {
        $category = $request->get('category', 'all');
        $page = (int)$request->get('page', 1);

        return [
            'category' => $category,
            'page' => $page,
            'items' => $this->loadProducts($category, $page),
        ];
    }
}
```

The first eligible `200` response is stored in Redis. Later requests with the same resolved key can reuse the cached
body and may return `304 Not Modified` when `If-None-Match` matches.

The filter already keeps `Accept: application/json` in a separate variant.
Because lookup also covers `POST`, only cache responses that are valid to reuse for POST requests.

---

## Core Concepts {#core-concepts}

### Page Cache {#page-cache}

`PageCache` marks one action as cacheable for a TTL.
The default TTL is `3` seconds, so set it explicitly for real pages.

```php
namespace App\Controller;

use Switon\HttpCache\Attribute\PageCache;

class ExamplePageCacheTtl
{
    #[PageCache(ttl: 600)]
    public function cacheableAction(): void
    {
    }
}
```

### Cache Key {#cache-key}

The default key is the controller action itself.
If you pass `key`, it adds request-derived values or fixed fragments on top of that controller action key.

For real pages, pass `key` explicitly and include every value that can change the response.

```php
namespace App\Controller;

use Switon\HttpCache\Attribute\PageCache;

class ExamplePageCacheKeys
{
    #[PageCache(ttl: 600, key: ['category', 'page'])]
    public function listAction(): void
    {
    }

    #[PageCache(ttl: 600, key: ['lang' => 'en', 'category'])]
    public function localizedAction(): void
    {
    }
}
```

### Revalidation {#revalidation}

Cached entries carry an `ETag`.
When the client sends `If-None-Match`, matching content can return `304 Not Modified` instead of the body again.
If the rendered body keeps the same `ETag`, the cache storage step can also short-circuit to `304`.

### Redis Storage {#redis-storage}

The filter stores `content`, `content-type`, `etag`, and `ttl` in Redis, and keeps the body gzip-compressed.
It uses your app's `Switon\Redis\ClientInterface` binding.

### Method Scope {#method-scope}

Lookup applies to `GET`, `POST`, and `HEAD`.
Fresh writes only happen for successful `200` responses.

---

## Configuration {#configuration}

```yaml
# switon.yml
Switon\HttpCache\Filter\PageCacheFilter:
  prefix: 'cache:myapp:page_cache:'
  redisCache: '#pageCache'
```

Use this section in `switon.yml` when you need a custom prefix or a separate Redis client.

The default prefix is `cache:{app_id}:page_cache:`.
If you do not set `redisCache`, the filter uses the default `Switon\Redis\ClientInterface` binding.

---

## Option Discovery {#option-discovery}

Use the current option surface directly:

```bash
bash bin/console config:options 'Switon\HttpCache\Filter\PageCacheFilter'
```

If you configure a named Redis client for page cache, inspect `Switon\Redis\ClientInterface` too.

---

## Keep in Mind {#keep-in-mind}

- Keep every output-changing value in the cache key.
- Do not expect auto-invalidation when domain data changes.
- Cache hits stop the action flow with `StopFlow`.
- Use page cache for the final response body, not for reusable application data.

---

## Next {#next}

- **To debug cache misses and key design:** [FAQ](faq.md#http-cache-faq)
- **To understand the design trade-offs:** [Design Rationale](design.md#design-rationale)
