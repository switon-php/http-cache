<?php

declare(strict_types=1);

namespace Switon\HttpCache\Tests\Unit;

use Switon\HttpCache\Attribute\PageCache;
use Switon\HttpCache\Tests\TestCase;

class PageCacheAttributeTest extends TestCase
{
    public function testDefaults(): void
    {
        $attribute = new PageCache();

        $this->assertSame(3, $attribute->ttl);
        $this->assertNull($attribute->key);
    }

    public function testCustomValues(): void
    {
        $attribute = new PageCache(120, ['id', 'lang' => 'zh-CN']);

        $this->assertSame(120, $attribute->ttl);
        $this->assertSame(['id', 'lang' => 'zh-CN'], $attribute->key);
    }
}

