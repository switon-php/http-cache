<?php

declare(strict_types=1);

namespace Switon\HttpCache\Filter;

/**
 * Holds request-local state shared by page-cache filter listeners.
 *
 * Use when <code>\Switon\HttpCache\Filter\PageCacheFilter</code> needs to pass
 * cache metadata between <code>RequestReady</code> and <code>HeadersSending</code>.
 *
 * @see \Switon\HttpCache\Filter\PageCacheFilter
 */
class PageCacheFilterContext
{
    /** Cache lifetime in seconds. */
    public ?int $ttl = null;

    /** Resolved Redis hash key for the current request. */
    public string $key;

    /** Incoming If-None-Match header value. */
    public string $if_none_match;

    /** Indicates whether response content was served from cache. */
    public bool $cache_used = false;
}
