<?php

declare(strict_types=1);

namespace WpCarve\Includes;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;

/**
 * Remembers each lookup with the file it was made from, so a cached render can
 * repeat it: a relative target missing from `sub/a.crv` is looked for in `sub/`.
 */
class RecordingResolver implements IncludeResolverInterface
{
    /**
     * @var array<string, array{0: string, 1: string|null}>
     */
    private array $lookups = [];

    public function __construct(private IncludeResolverInterface $inner)
    {
    }

    public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
    {
        $including = $context->getIncludingPath();
        $this->lookups[$path . "\0" . $including] = [$path, $including];

        return $this->inner->resolve($path, $context);
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public function lookups(): array
    {
        return array_values($this->lookups);
    }
}
