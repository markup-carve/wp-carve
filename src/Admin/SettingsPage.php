<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WpCarve\Settings;

/**
 * Settings screen under Settings -> Carve Markup.
 */
class SettingsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
    }

    public function menu(): void
    {
        add_options_page(
            __('Carve Markup', 'carve-markup'),
            __('Carve Markup', 'carve-markup'),
            'manage_options',
            'wp-carve',
            [$this, 'renderPage'],
        );
    }

    public function settings(): void
    {
        register_setting('wp_carve', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
    }

    /**
     * @param mixed $input
     *
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = Settings::defaults();
        $out = [];
        foreach ($defaults as $key => $default) {
            if (is_bool($default)) {
                $out[$key] = !empty($input[$key]);
            } elseif (is_int($default)) {
                $out[$key] = (int)($input[$key] ?? $default);
            } else {
                $out[$key] = sanitize_text_field((string)($input[$key] ?? $default));
            }
        }

        return $out;
    }

    public function renderPage(): void
    {
        $s = Settings::all();
        $profiles = ['full', 'article', 'comment', 'minimal'];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Carve Markup', 'carve-markup') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('wp_carve');
        echo '<table class="form-table" role="presentation">';

        $this->checkboxRow($s, 'enable_posts', __('Enable Carve in posts/pages', 'carve-markup'));
        $this->checkboxRow($s, 'enable_comments', __('Enable Carve in comments', 'carve-markup'));
        $this->checkboxRow($s, 'enable_shortcode', __('Enable [carve] shortcode', 'carve-markup'));
        $this->checkboxRow($s, 'safe_mode', __('Safe mode for posts (XSS hardening)', 'carve-markup'));
        $this->selectRow($s, 'post_profile', __('Post content profile', 'carve-markup'), $profiles);
        $this->selectRow($s, 'comment_profile', __('Comment content profile', 'carve-markup'), $profiles);
        $this->checkboxRow($s, 'toc_enabled', __('Table of contents', 'carve-markup'));
        $this->checkboxRow($s, 'permalinks_enabled', __('Heading permalinks', 'carve-markup'));
        $this->checkboxRow($s, 'smart_quotes', __('Smart quotes', 'carve-markup'));
        $this->checkboxRow($s, 'mermaid_enabled', __('Mermaid diagrams', 'carve-markup'));
        $this->checkboxRow($s, 'normalize_tabs', __('Normalize tabs to spaces in code', 'carve-markup'));

        echo '<tr><th colspan="2"><h2>' . esc_html__('Enhancements', 'carve-markup') . '</h2></th></tr>';

        $this->checkboxRow($s, 'live_preview', __('Live in-browser preview (carve-js)', 'carve-markup'));
        $this->checkboxRow($s, 'paste_ingest', __('Convert pasted Markdown/Djot/BBCode/HTML', 'carve-markup'));
        $this->checkboxRow($s, 'frontmatter_meta', __('Map frontmatter to post meta/SEO', 'carve-markup'));
        $this->checkboxRow($s, 'render_cache', __('Cache rendered HTML on save', 'carve-markup'));

        echo '</table>';
        submit_button();
        echo '</form></div>';
    }

    /**
     * @param array<string, mixed> $s
     * @param string $key
     * @param string $label
     */
    private function checkboxRow(array $s, string $key, string $label): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        printf(
            '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s> %s</label></td></tr>',
            esc_html($label),
            esc_attr($name),
            checked(!empty($s[$key]), true, false),
            esc_html__('Enabled', 'carve-markup'),
        );
    }

    /**
     * @param array<string, mixed> $s
     * @param string $key
     * @param string $label
     * @param array<int, string> $options
     */
    private function selectRow(array $s, string $key, string $label, array $options): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        $current = (string)($s[$key] ?? '');
        $html = '<tr><th scope="row">' . esc_html($label) . '</th><td><select name="' . esc_attr($name) . '">';
        foreach ($options as $opt) {
            $html .= sprintf('<option value="%s" %s>%s</option>', esc_attr($opt), selected($current, $opt, false), esc_html($opt));
        }
        $html .= '</select></td></tr>';
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* above
    }
}
