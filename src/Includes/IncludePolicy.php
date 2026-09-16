<?php

declare(strict_types=1);

namespace WpCarve\Includes;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeContext;
use Throwable;
use WP_Post;
use WpCarve\Plugin;
use WpCarve\Settings;

/**
 * Who may expand `{{ file.crv }}` include directives.
 *
 * A post expands only if the user who last saved it held `unfiltered_html`.
 * The bit is written by the save path alone: writes to it from anywhere else
 * (REST meta, meta_input, custom fields) are refused.
 */
class IncludePolicy
{
    /**
     * @var string
     */
    public const TRUST_META = '_wpcarve_include_trusted';

    /**
     * @var string
     */
    public const CAPABILITY = 'unfiltered_html';

    private static bool $writing = false;

    public function register(): void
    {
        add_action('save_post', [$this, 'onSave'], 1, 2);
        add_filter('add_post_metadata', [self::class, 'guardMetaWrite'], 1, 3);
        add_filter('update_post_metadata', [self::class, 'guardMetaWrite'], 1, 3);
    }

    /**
     * Re-evaluated on every save, autosaves included: an autosave of a draft
     * writes the post itself.
     */
    public function onSave(int $postId, WP_Post $post): void
    {
        if (wp_is_post_revision($postId)) {
            return;
        }

        if (!self::userTrusted(get_current_user_id())) {
            delete_post_meta($postId, self::TRUST_META);

            return;
        }

        self::$writing = true;
        try {
            update_post_meta($postId, self::TRUST_META, '1');
        } finally {
            self::$writing = false;
        }
    }

    /**
     * Short-circuits any add/update of the trust bit that does not come from
     * onSave(). A non-null return is core's signal to skip the write.
     */
    public static function guardMetaWrite(mixed $check, mixed $objectId, mixed $metaKey): mixed
    {
        if ($metaKey === self::TRUST_META && !self::$writing) {
            return false;
        }

        return $check;
    }

    public static function userTrusted(int $userId): bool
    {
        return $userId > 0 && user_can($userId, self::CAPABILITY);
    }

    public static function enabled(): bool
    {
        return self::root() !== '';
    }

    /**
     * The configured root exactly as stored. It goes to the resolver as-is: the
     * resolver refuses a non-absolute root, and canonicalizing it here first
     * would turn a relative root into one anchored at the working directory.
     */
    public static function root(): string
    {
        return (string)(Settings::get('include_root') ?? '');
    }

    public static function postTrusted(int $postId): bool
    {
        return $postId > 0 && get_post_meta($postId, self::TRUST_META, true) === '1';
    }

    /**
     * A report for rendering a post's own content, or null when it may not
     * expand. Block sources must be ones the post itself saved, so a synced
     * pattern or widget rendered inside a trusted post does not borrow its bit.
     */
    public static function reportForPost(?WP_Post $post, ?string $blockSource = null): ?IncludeReport
    {
        if ($post === null || !self::enabled() || !self::postTrusted($post->ID)) {
            return null;
        }
        if ($blockSource !== null && !self::postSavedBlockSource($post, $blockSource)) {
            return null;
        }

        return new IncludeReport(self::root());
    }

    /**
     * The preview gate: the bit the current user's save would write.
     */
    public static function reportForCurrentUser(): ?IncludeReport
    {
        if (!self::enabled() || !self::userTrusted(get_current_user_id())) {
            return null;
        }

        return new IncludeReport(self::root());
    }

    private static function postSavedBlockSource(WP_Post $post, string $source): bool
    {
        foreach (parse_blocks((string)$post->post_content) as $block) {
            if (self::blockHoldsSource($block, $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     * @param string $source
     */
    private static function blockHoldsSource(array $block, string $source): bool
    {
        if (
            Plugin::isCarveBlockName((string)($block['blockName'] ?? ''))
            && (string)($block['attrs']['carve'] ?? '') === $source
        ) {
            return true;
        }
        foreach ((array)($block['innerBlocks'] ?? []) as $inner) {
            if (is_array($inner) && self::blockHoldsSource($inner, $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current state of every dependency a render touched, resolved or not, so
     * a cached render goes stale when a target changes, appears or vanishes.
     * Reads go through the engine's resolver, so containment still applies.
     *
     * @param string $root
     * @param array<int, string> $targets
     */
    public static function fingerprint(string $root, array $targets): string
    {
        try {
            $resolver = new FilesystemIncludeResolver($root, allowAbsolutePaths: true);
        } catch (Throwable) {
            return 'root-refused';
        }

        $states = [];
        foreach ($targets as $target) {
            try {
                $resolved = $resolver->resolve($target, new IncludeContext());
                $states[] = $target . "\0" . md5($resolved->getSource());
            } catch (Throwable) {
                $states[] = $target . "\0-";
            }
        }

        return md5($root . "\0" . implode("\n", $states));
    }
}
