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
    $GLOBALS['_wpcarve_test_posts'][$id] = $post;
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_content = '';

        public string $post_excerpt = '';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $tag, mixed ...$args): void
    {
    }
}

if (!function_exists('get_post')) {
    function get_post(?int $id = null): ?WP_Post
    {
        return $GLOBALS['_wpcarve_test_posts'][$id] ?? null;
    }
}

if (!function_exists('has_blocks')) {
    function has_blocks(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
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
