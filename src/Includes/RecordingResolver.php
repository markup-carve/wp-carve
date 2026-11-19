<?php

declare(strict_types=1);

namespace WpCarve\Includes;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Include\IncludeResolver;

/**
 * Remembers each lookup with the file it was made from, so a cached render can
 * repeat it: a relative target missing from `sub/a.crv` is looked for in `sub/`.
 */
class RecordingResolver implements IncludeResolver
{
    /**
     * @var array<string, array{0: string, 1: string|null}>
     */
    private array $lookups = [];

    public function __construct(private IncludeResolver $inner)
    {
    }

    /**
     * @param array{path: string, options: array<mixed>, section?: mixed, from?: string, budget: int} $request
     *
     * @return array{text: string, id: string}|array{error: string, id?: string}
     */
    public function resolve(array $request): array
    {
        $path = $request['path'];
        $including = $request['from'] ?? null;
        $this->lookups[$path . "\0" . $including] = [$path, $including];

        return $this->inner->resolve($request);
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public function lookups(): array
    {
        return array_values($this->lookups);
    }
}
