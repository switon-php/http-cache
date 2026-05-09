<?php

declare(strict_types=1);

namespace Switon\HttpCache\Attribute;

use Attribute;

/**
 * Declares full-page cache settings for one controller action.
 *
 * Use when action responses are deterministic for the selected key parameters
 * and can be reused for a configured TTL.
 *
 * Road-signs:
 * - action attr; filter read/write hooks
 * - ttl+key
 *
 * @see \Switon\HttpCache\Filter\PageCacheFilter
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PageCache
{
    /** Cache lifetime in seconds. */
    public int $ttl;

    /** @var array<int, string>|array<string, mixed>|null Cache key rule list or fixed key-value map. */
    public ?array $key;

    /**
     * @param int $ttl Cache lifetime in seconds
     * @param array<int, string>|array<string, mixed>|null $key Request key rules or fixed key-value pairs
     */
    public function __construct(int $ttl = 3, ?array $key = null)
    {
        $this->ttl = $ttl;
        $this->key = $key;
    }
}
