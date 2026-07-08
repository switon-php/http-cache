<?php

declare(strict_types=1);

namespace Switon\HttpCache\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Switon\\HttpCache\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
);

abstract class TestCase extends BaseTestCase
{
}
