# Switon HTTP Cache Package

HTTP response caching filter for Switon Framework.

## Installation

```bash
composer require switon/http-cache
```

**Requirements:** PHP 8.3+

## Quick Start

```php
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

Docs: https://docs.switon.dev/latest/http-cache

## License

MIT.
