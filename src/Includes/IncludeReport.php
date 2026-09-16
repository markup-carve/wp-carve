<?php

declare(strict_types=1);

namespace WpCarve\Includes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What one include expansion touched and refused. Warnings keep the rule and
 * message only: the engine's `detail` channel carries resolver text with
 * absolute server paths and never leaves this plugin.
 */
class IncludeReport
{
    /**
     * @var string
     */
    public const RULE_ROOT_REFUSED = 'include-root-refused';

    /**
     * @var array<int, array{target: string, resolved: bool}>
     */
    private array $dependencies = [];

    /**
     * @var array<int, array{rule: string, message: string}>
     */
    private array $warnings = [];

    public function __construct(private string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @param array<\MarkupCarve\Carve\Transform\IncludeDependency> $dependencies
     * @param array<\MarkupCarve\Carve\Exception\ParseWarning> $warnings
     * @param int $suppressed
     */
    public function record(array $dependencies, array $warnings, int $suppressed): void
    {
        foreach ($dependencies as $dependency) {
            $this->dependencies[] = ['target' => $dependency->getTarget(), 'resolved' => $dependency->isResolved()];
        }
        foreach ($warnings as $warning) {
            $this->warnings[] = ['rule' => (string)$warning->getRule(), 'message' => $warning->getMessage()];
        }
        if ($suppressed > 0) {
            $this->warnings[] = ['rule' => 'include-warnings-suppressed', 'message' => sprintf('%d further include warnings were suppressed.', $suppressed)];
        }
    }

    public function refuseRoot(): void
    {
        $this->warnings[] = [
            'rule' => self::RULE_ROOT_REFUSED,
            'message' => 'The include root is not an absolute path to an existing directory, so includes were not expanded.',
        ];
    }

    /**
     * @return array<int, array{target: string, resolved: bool}>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * @return array<int, string>
     */
    public function targets(): array
    {
        return array_column($this->dependencies, 'target');
    }

    /**
     * @return array<int, array{rule: string, message: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
