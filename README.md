# Switon HTTP Cache Package

[![HTTP Cache CI](https://img.shields.io/github/actions/workflow/status/switon-php/http-cache/ci.yml?branch=main&label=HTTP%20Cache%20CI)](https://github.com/switon-php/http-cache/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's page cache filter for Redis-backed action caching and conditional HTTP responses.

## Highlights

- **Action-level page cache:** `#[PageCache]` marks controller actions cacheable with a TTL.
- **Redis-backed storage:** cached responses are stored in Redis.
- **Request-aware keys:** keys can use selected request fields or fixed values.
- **Conditional responses:** cache hits can still support `304 Not Modified`.
- **Scoped lookup:** cache lookup applies only to eligible request methods.

## Installation

```bash
composer require switon/http-cache
```

## Quick Start

```php
use Switon\HttpCache\Attribute\PageCache;
use Switon\Routing\Attribute\GetMapping;

class ProductController
{
    #[GetMapping('/products')]
    #[PageCache(ttl: 600, key: ['category', 'page'])]
    public function listAction(string $category = 'all', int $page = 1): array
    {
        return [
            'category' => $category,
            'page' => $page,
            'items' => $this->loadProducts($category, $page),
        ];
    }
}
```

Docs: https://docs.switon.dev/latest/http-cache

## License

MIT.
