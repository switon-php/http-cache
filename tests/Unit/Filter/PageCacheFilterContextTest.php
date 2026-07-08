<?php

declare(strict_types=1);

namespace Switon\HttpCache\Tests\Unit\Filter;

use Switon\HttpCache\Filter\PageCacheFilterContext;
use PHPUnit\Framework\TestCase;

class PageCacheFilterContextTest extends TestCase
{
    public function testDefaultState(): void
    {
        $context = new PageCacheFilterContext();

        $this->assertNull($context->ttl);
        $this->assertFalse($context->cache_used);
    }
}
