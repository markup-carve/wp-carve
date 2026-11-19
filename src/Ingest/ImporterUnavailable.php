<?php

declare(strict_types=1);

namespace WpCarve\Ingest;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * An import format the Carve 0.2 engine does not provide yet.
 */
final class ImporterUnavailable extends RuntimeException
{
    public function __construct(public string $format, public string $issue)
    {
        parent::__construct(sprintf(
            'Importing %s is not available yet in Carve 0.2%s.',
            $format,
            $issue !== '' ? ' (waits on ' . $issue . ')' : '',
        ));
    }
}
