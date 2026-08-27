<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: Composer autoload + a minimal set of WordPress function
 * and class stubs so the plugin's pure units (Converter, Migrator) can run
 * without a full WordPress install. Just enough surface for the units under
 * test -- not a WP shim.
 */

require __DIR__ . '/../vendor/autoload.php';

defined('ABSPATH') || define('ABSPATH', '/tmp/wp/');
defined('WPCARVE_VERSION') || define('WPCARVE_VERSION', '0.1.0');
defined('WPCARVE_FILE') || define('WPCARVE_FILE', __DIR__ . '/../carve-markup.php');
defined('WPCARVE_DIR') || define('WPCARVE_DIR', __DIR__ . '/../');
defined('WPCARVE_URL') || define('WPCARVE_URL', 'http://example.test/wp-content/plugins/wpcarve/');

// In-memory post store driven by tests via wpcarve_test_set_post().
$GLOBALS['_wpcarve_test_posts'] = [];
$GLOBALS['_wpcarve_test_meta'] = [];

/**
 * @param array<string, mixed> $fields
 */
function wpcarve_test_set_post(int $id, array $fields): void
{
    $post = new WP_Post();
    $post->ID = $id;
    $post->post_content = (string)($fields['post_content'] ?? '');
    $post->post_excerpt = (string)($fields['post_excerpt'] ?? '');
    $post->post_type = (string)($fields['post_type'] ?? 'post');
    $post->post_author = (int)($fields['post_author'] ?? 0);
    $post->post_status = (string)($fields['post_status'] ?? 'publish');
    $post->post_password = (string)($fields['post_password'] ?? '');
    $post->post_name = (string)($fields['post_name'] ?? 'demo-post');
    $post->post_title = (string)($fields['post_title'] ?? 'Demo post');
    $GLOBALS['_wpcarve_test_posts'][$id] = $post;
    $GLOBALS['_wpcarve_test_current_post'] = $id;
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_type = 'post';

        public int $post_author = 0;

        public string $post_status = 'publish';

        public string $post_password = '';

        public string $post_name = '';

        public string $post_title = '';
    }
}

/**
 * A real hook registry, not a pair of no-ops.
 *
 * `apply_filters` used to return its value untouched and `do_action` used to do
 * nothing, so every filter and action this plugin documents was unreachable
 * from a test - including `wpcarve_converter`, which docs/hooks.md calls "the
 * supported extension point". A change that stopped firing it, or fired it with
 * the wrong arguments, broke every integration and no test could tell.
 *
 * Small on purpose: priority ordering and argument count, which is what the
 * hooks here rely on. Not `current_filter`, not `remove_action` by callable
 * identity, not `did_action`. Reach for a WordPress test suite before growing
 * this into one.
 */
if (!function_exists('wpcarve_test_reset_hooks')) {
    /** @var array<string, array<int, array<int, callable>>> */
    $GLOBALS['wpcarve_test_hooks'] = [];

    function wpcarve_test_reset_hooks(): void
    {
        $GLOBALS['wpcarve_test_hooks'] = [];
    }

    function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_filter($tag, $callback, $priority, $acceptedArgs);
    }

    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['wpcarve_test_hooks'][$tag][$priority][] = [$callback, $acceptedArgs];
    }

    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach (wpcarve_test_callbacks($tag) as [$callback, $acceptedArgs]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $acceptedArgs));
        }

        return $value;
    }

    function do_action(string $tag, mixed ...$args): void
    {
        foreach (wpcarve_test_callbacks($tag) as [$callback, $acceptedArgs]) {
            $callback(...array_slice($args, 0, $acceptedArgs));
        }
    }

    /**
     * @return array<int, array{0: callable, 1: int}>
     */
    function wpcarve_test_callbacks(string $tag): array
    {
        $byPriority = $GLOBALS['wpcarve_test_hooks'][$tag] ?? [];
        ksort($byPriority);

        return array_merge(...array_values($byPriority)) ?: [];
    }
}

if (!function_exists('get_post')) {
    function get_post(?int $id = null): ?WP_Post
    {
        $id ??= (int)($GLOBALS['_wpcarve_test_current_post'] ?? 0);

        return $GLOBALS['_wpcarve_test_posts'][$id] ?? null;
    }
}

if (!function_exists('has_blocks')) {
    function has_blocks(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }
}

if (!function_exists('has_block')) {
    function has_block(string $blockName, WP_Post|string|null $post = null): bool
    {
        $content = $post instanceof WP_Post ? $post->post_content : (string)$post;

        return str_contains($content, '<!-- wp:' . $blockName);
    }
}

if (!function_exists('wp_slash')) {
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }

        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $data): int
    {
        $id = (int)($data['ID'] ?? 0);
        if (isset($GLOBALS['_wpcarve_test_posts'][$id]) && isset($data['post_content'])) {
            $GLOBALS['_wpcarve_test_posts'][$id]->post_content = (string)$data['post_content'];
        }

        return $id;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $id, string $key, mixed $value): bool
    {
        $GLOBALS['_wpcarve_test_meta'][$id][$key] = $value;

        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $id, string $key, bool $single = false): mixed
    {
        return $GLOBALS['_wpcarve_test_meta'][$id][$key] ?? '';
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['_wpcarve_test_options'][$name] ?? $default;
    }
}

if (!function_exists('get_the_ID')) {
    function get_the_ID(): int
    {
        return (int)($GLOBALS['_wpcarve_test_current_post'] ?? 0);
    }
}

if (!function_exists('get_post_field')) {
    function get_post_field(string $field, int $id = 0): mixed
    {
        return $GLOBALS['_wpcarve_test_posts'][$id]->$field ?? '';
    }
}

if (!function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool
    {
        return (bool)($GLOBALS['_wpcarve_test_caps'][$userId][$capability] ?? false);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('is_singular')) {
    function is_singular(): bool
    {
        return true;
    }
}

if (!function_exists('in_the_loop')) {
    function in_the_loop(): bool
    {
        return true;
    }
}

if (!function_exists('is_main_query')) {
    function is_main_query(): bool
    {
        return true;
    }
}

if (!function_exists('is_feed')) {
    function is_feed(): bool
    {
        return false;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(WP_Post|int $post): string
    {
        $id = $post instanceof WP_Post ? $post->ID : $post;

        return 'http://example.test/?p=' . $id;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(string $key, string $value, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value);
    }
}

if (!function_exists('wp_kses_allowed_html')) {
    /**
     * @return array<string, array<string, bool>>
     */
    function wp_kses_allowed_html(string $context = 'post'): array
    {
        return [
            'a' => ['href' => true, 'title' => true],
            'p' => [],
            'pre' => ['class' => true],
            'code' => ['class' => true],
        ];
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        $GLOBALS['_wpcarve_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int $id): bool
    {
        return false;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $id, string $key): bool
    {
        unset($GLOBALS['_wpcarve_test_meta'][$id][$key]);

        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0): string|false
    {
        return json_encode($data, $flags);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim((string)preg_replace('/[\r\n\t ]+/', ' ', strip_tags($str)));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('parse_blocks')) {
    /**
     * Minimal mirror of core's comment-delimited block parser: enough for
     * well-formed `<!-- wp:name {json} /-->` blocks; anything else is
     * freeform (blockName null), matching how core treats malformed input.
     *
     * @return array<int, array{blockName: ?string, attrs: array<string, mixed>}>
     */
    function parse_blocks(string $content): array
    {
        $blocks = [];
        $pattern = '/<!--\s+wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+({(?:(?!}\s+\/?-->).)*})\s+\/?-->/s';
        if (preg_match_all($pattern, $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $attrs = json_decode($match[3], true);
                if (!is_array($attrs)) {
                    continue;
                }
                $blocks[] = ['blockName' => rtrim($match[1], '/') . '/' . $match[2], 'attrs' => $attrs];
            }
        }
        if ($blocks === []) {
            $blocks[] = ['blockName' => null, 'attrs' => []];
        }

        return $blocks;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
    {
        $text = strip_tags($text);
        if ($removeBreaks) {
            $text = trim((string)preg_replace('/[\r\n\t ]+/', ' ', $text));
        }

        return $text;
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $numWords = 55, ?string $more = null): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) <= $numWords) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $numWords)) . ($more ?? '&hellip;');
    }
}
