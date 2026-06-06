<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        return;
    }
}

throw new RuntimeException('Unable to locate vendor/autoload.php for PHPStan bootstrap.');
