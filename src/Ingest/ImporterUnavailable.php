<?php

declare(strict_types=1);

namespace WpCarve\Ingest;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * An import format the server cannot convert.
 */
final class ImporterUnavailable extends RuntimeException
{
    public function __construct(public string $format)
    {
        parent::__construct($format === 'bbcode'
            ? 'BBCode is converted in the block editor in Carve 0.2; the server does not import it.'
            : sprintf('Importing %s is not supported.', $format));
    }

    /**
     * Whether the block editor converts this format in the browser.
     */
    public function inBrowser(): bool
    {
        return $this->format === 'bbcode';
    }
}
