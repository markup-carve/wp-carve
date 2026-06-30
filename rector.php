<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

return RectorConfig::configure()
    ->withBootstrapFiles([
        __DIR__ . '/rector-bootstrap.php',
    ])
    ->withPaths([
        // Only downgrade bundled packages that use PHP 8.1/8.2-only syntax
        // (readonly classes, trait constants, enum ->value in const exprs).
        // WordPress.org's SVN pre-commit lint runs on an older PHP.
        __DIR__ . '/vendor/torchlight/engine',
        __DIR__ . '/vendor/phiki/phiki',
        __DIR__ . '/vendor/nette/schema',
        __DIR__ . '/vendor/nette/utils',
    ])
    ->withSets([
        // DOWN_TO_PHP_80 to catch traits with constants and other 8.1/8.2 features
        DowngradeLevelSetList::DOWN_TO_PHP_80,
    ]);
