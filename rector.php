<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

return RectorConfig::configure()
    ->withBootstrapFiles([
        __DIR__ . '/rector-bootstrap.php',
    ])
    ->withPaths([
        // The whole staged vendor tree, not a hand-written list of packages.
        // WordPress.org's SVN pre-commit lint parses every committed file on an
        // older PHP, so every bundled package has to lose its 8.1/8.2-only
        // syntax - and a list of package names silently stops covering the one
        // that gets added next. dereuromark/media-embed was such a package: it
        // shipped eight files of readonly promoted properties that nothing
        // downgraded, because the list named five packages and not that one.
        __DIR__ . '/vendor',
    ])
    ->withSets([
        // Down to 8.0, below the plugin's own floor, because the lint behind
        // WordPress.org's SVN commit runs older than the versions users get.
        DowngradeLevelSetList::DOWN_TO_PHP_80,
    ]);
