<?php

declare(strict_types=1);

namespace WpCarve\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WpCarve\Diagrams;
use WpCarve\Settings;

/**
 * Settings screen under Settings -> Carve Markup.
 *
 * Renders a tabbed, card-grid layout (not the default form-table) so the wide
 * admin area is used and the page stays short.
 */
class SettingsPage
{
    /**
     * @var string
     */
    private const MENU_SLUG = 'wpcarve';

    /**
     * @var array<int, string>
     */
    private const MULTILINE_KEYS = ['abbreviations'];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_options_page(
            __('Carve Markup', 'carve-markup'),
            __('Carve Markup', 'carve-markup'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }
        wp_enqueue_style('wpcarve-admin-settings', WPCARVE_URL . 'assets/css/admin-settings.css', [], WPCARVE_VERSION);
        wp_enqueue_script('wpcarve-admin-settings', WPCARVE_URL . 'assets/js/admin-settings.js', [], WPCARVE_VERSION, true);
    }

    public function settings(): void
    {
        register_setting('wpcarve', Settings::OPTION, [
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
            } elseif (in_array($key, self::MULTILINE_KEYS, true)) {
                // Preserve newlines for multi-line textareas (e.g. one
                // abbreviation per line); sanitize_text_field would flatten them.
                $out[$key] = sanitize_textarea_field((string)($input[$key] ?? $default));
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

        $tabs = [
            'general' => __('General', 'carve-markup'),
            'content' => __('Content', 'carve-markup'),
            'code' => __('Code & diagrams', 'carve-markup'),
            'advanced' => __('Advanced', 'carve-markup'),
        ];

        echo '<div class="wrap wpcarve-settings">';
        echo '<h1>' . esc_html__('Carve Markup', 'carve-markup') . '</h1>';
        echo '<p class="wpcarve-intro">' . esc_html__('A post-Markdown markup language for WordPress. Each feature below is independent; diagram libraries load only on pages that use them.', 'carve-markup') . '</p>';

        echo '<h2 class="nav-tab-wrapper wpcarve-tabs">';
        $first = true;
        foreach ($tabs as $id => $label) {
            printf(
                '<a href="#%1$s" class="nav-tab%2$s" data-tab="%1$s">%3$s</a>',
                esc_attr($id),
                $first ? ' nav-tab-active' : '',
                esc_html($label),
            );
            $first = false;
        }
        echo '</h2>';

        echo '<form method="post" action="options.php">';
        settings_fields('wpcarve');

        // General.
        $this->panelStart('general', true);
        $this->group(__('Where Carve renders', 'carve-markup'));
        $this->grid();
        $this->toggle($s, 'enable_posts', __('Posts', 'carve-markup'), __('Render post content as Carve.', 'carve-markup'));
        $this->toggle($s, 'enable_pages', __('Pages', 'carve-markup'), __('Render page content as Carve.', 'carve-markup'));
        $this->toggle($s, 'enable_comments', __('Comments', 'carve-markup'), __('Render comments as Carve (uses the comment profile).', 'carve-markup'));
        $this->toggle($s, 'enable_shortcode', __('[carve] shortcode', 'carve-markup'), __('Render Carve inside a [carve] shortcode.', 'carve-markup'));
        $this->toggle($s, 'enable_excerpts', __('Excerpts', 'carve-markup'), __('Render excerpts as Carve.', 'carve-markup'));
        $this->gridEnd();
        $this->group(__('Content profiles', 'carve-markup'));
        $this->grid();
        $this->select($s, 'post_profile', __('Post profile', 'carve-markup'), $profiles, __('Feature set allowed in posts and pages. Only Full permits raw HTML (via =html blocks), which is still sanitized through wp_kses; Article, Comment and Minimal render raw HTML as escaped text.', 'carve-markup'));
        $this->select($s, 'comment_profile', __('Comment profile', 'carve-markup'), $profiles, __('Feature set allowed in comments. Comments never allow raw HTML regardless of profile.', 'carve-markup'));
        $softBreaks = [
            'newline' => __('Preserve newline', 'carve-markup'),
            'space' => __('Space (join lines)', 'carve-markup'),
            'br' => __('Line break (<br>)', 'carve-markup'),
        ];
        $this->select($s, 'post_soft_break', __('Post soft breaks', 'carve-markup'), $softBreaks, __('How a single newline inside a paragraph renders.', 'carve-markup'));
        $this->select($s, 'comment_soft_break', __('Comment soft breaks', 'carve-markup'), $softBreaks, __('Often set to line break so comment newlines show.', 'carve-markup'));
        $this->gridEnd();
        $this->panelEnd();

        // Content.
        $this->panelStart('content');
        $this->group(__('Structure', 'carve-markup'));
        $this->grid();
        $this->number($s, 'heading_shift', __('Heading shift', 'carve-markup'), __('Demote headings by N levels (0-5).', 'carve-markup'));
        $this->toggle($s, 'permalinks_enabled', __('Heading permalinks', 'carve-markup'), __('Add click-to-copy anchors to headings.', 'carve-markup'));
        $this->gridEnd();
        $this->group(__('Table of contents', 'carve-markup'));
        $this->grid();
        $this->toggle($s, 'toc_enabled', __('Table of contents', 'carve-markup'), __('Generate a TOC from headings.', 'carve-markup'));
        $this->select($s, 'toc_position', __('Position', 'carve-markup'), ['top', 'bottom', 'none'], '', 'toc_enabled');
        $this->select($s, 'toc_list_type', __('List type', 'carve-markup'), ['ul', 'ol'], '', 'toc_enabled');
        $this->gridEnd();
        $this->group(__('Typography', 'carve-markup'));
        $this->grid();
        $this->toggle($s, 'smart_quotes', __('Smart quotes', 'carve-markup'), __('Curly quotes, dashes, and ellipses.', 'carve-markup'));
        $this->text($s, 'smart_quotes_locale', __('Locale', 'carve-markup'), __('Quote style, e.g. en, de, fr.', 'carve-markup'), 'smart_quotes');
        $this->toggle($s, 'normalize_tabs', __('Normalize tabs', 'carve-markup'), __('Convert leading tabs in code to spaces.', 'carve-markup'));
        $this->gridEnd();
        $this->group(__('Abbreviations', 'carve-markup'));
        $this->textarea($s, 'abbreviations', __('Site-wide abbreviations', 'carve-markup'), __('One per line as KEY: expansion (e.g. HTML: HyperText Markup Language). Matching words get an <abbr> tooltip everywhere.', 'carve-markup'));
        $this->group(__('Media embeds', 'carve-markup'));
        $this->grid();
        $this->toggle($s, 'media_embed_enabled', __('Media embeds', 'carve-markup'), __('Embed YouTube, Vimeo, Spotify and 30+ providers via :youtube[ID] / :media[URL].', 'carve-markup'));
        $this->gridEnd();
        $this->panelEnd();

        // Code & diagrams.
        $this->panelStart('code');
        $this->group(__('Syntax highlighting', 'carve-markup'));
        $this->grid();
        $this->toggle($s, 'torchlight_enabled', __('Torchlight highlighting', 'carve-markup'), __('Server-side syntax highlighting (offline TextMate grammars, no API token).', 'carve-markup'));
        $this->select($s, 'torchlight_theme', __('Theme', 'carve-markup'), ['github-light', 'github-dark', 'nord', 'dracula', 'monokai'], '', 'torchlight_enabled');
        $this->toggle($s, 'torchlight_line_numbers', __('Line numbers by default', 'carve-markup'), __('Show a gutter on every code block.', 'carve-markup'), 'torchlight_enabled');
        $this->gridEnd();
        $this->group(__('Diagrams & charts', 'carve-markup'), __('Off by default. Each library loads only on pages that both enable and use it.', 'carve-markup'));
        $this->diagramGrid($s);
        $this->grid();
        $this->toggle($s, 'diagram_export', __('Diagram export controls', 'carve-markup'), __('Reveal Copy SVG / Download buttons when hovering a rendered diagram on the front end.', 'carve-markup'));
        $this->gridEnd();
        $this->panelEnd();

        // Advanced.
        $this->panelStart('advanced');
        $this->group(__('Editor & workflow', 'carve-markup'));
        $this->grid();
        $this->toggle($s, 'live_preview', __('Live preview', 'carve-markup'), __('In-browser preview while editing (carve-js).', 'carve-markup'));
        $this->select(
            $s,
            'visual_editor_mode',
            __('Visual editor', 'carve-markup'),
            [
                'disabled' => __('Disabled', 'carve-markup'),
                'enabled' => __('Visual tab available', 'carve-markup'),
                'enabled_default' => __('Visual tab, open by default', 'carve-markup'),
            ],
            __('Adds a Tiptap WYSIWYG tab to the Carve block (experimental).', 'carve-markup'),
        );
        $this->toggle($s, 'paste_ingest', __('Paste ingest', 'carve-markup'), __('Convert pasted Markdown/Djot/BBCode/HTML to Carve.', 'carve-markup'));
        $this->toggle($s, 'frontmatter_meta', __('Frontmatter to meta', 'carve-markup'), __('Map frontmatter to post meta and SEO fields.', 'carve-markup'));
        $this->toggle($s, 'render_cache', __('Render cache', 'carve-markup'), __('Cache rendered HTML on save.', 'carve-markup'));
        $this->gridEnd();
        $this->panelEnd();

        submit_button();
        echo '</form></div>';
    }

    private function panelStart(string $id, bool $active = false): void
    {
        printf('<div class="wpcarve-panel%s" data-panel="%s">', $active ? ' is-active' : '', esc_attr($id));
    }

    private function panelEnd(): void
    {
        echo '</div>';
    }

    private function group(string $title, string $desc = ''): void
    {
        echo '<h3 class="wpcarve-group">' . esc_html($title) . '</h3>';
        if ($desc !== '') {
            echo '<p class="wpcarve-group-desc">' . esc_html($desc) . '</p>';
        }
    }

    private function grid(): void
    {
        echo '<div class="wpcarve-grid">';
    }

    private function gridEnd(): void
    {
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $s
     * @param string $depends
     * @param string $desc
     * @param string $label
     * @param string $key
     */
    private function toggle(array $s, string $key, string $label, string $desc = '', string $depends = ''): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        printf(
            '<div class="wpcarve-card"%s>'
            . '<label class="wpcarve-toggle">'
            . '<input type="checkbox" name="%s" value="1" %s>'
            . '<span class="wpcarve-switch" aria-hidden="true"></span>'
            . '<span class="wpcarve-card-label">%s</span></label>'
            . '%s</div>',
            $depends !== '' ? ' data-depends="' . esc_attr($depends) . '"' : '',
            esc_attr($name),
            checked(!empty($s[$key]), true, false),
            esc_html($label),
            $desc !== '' ? '<p class="wpcarve-card-desc">' . esc_html($desc) . '</p>' : '',
        );
    }

    /**
     * @param array<string, mixed> $s
     * @param string $label
     * @param string $key
     * @param array<int|string, string> $options
     * @param string $depends
     * @param string $desc
     */
    private function select(array $s, string $key, string $label, array $options, string $desc = '', string $depends = ''): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        $current = (string)($s[$key] ?? '');
        $opts = '';
        // Accept both a flat list (value === label) and a value => label map.
        // (Avoid array_is_list(): Plugin Check gates it behind a newer WP min.)
        $isList = $options === array_values($options);
        // Use a distinct loop variable: reusing $label would clobber the field
        // label parameter used for the card heading below.
        foreach ($options as $value => $optLabel) {
            $value = $isList ? $optLabel : (string)$value;
            $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html((string)$optLabel));
        }
        printf(
            '<div class="wpcarve-card wpcarve-field"%s>'
            . '<span class="wpcarve-card-label">%s</span>'
            . '<select name="%s">%s</select>%s</div>',
            $depends !== '' ? ' data-depends="' . esc_attr($depends) . '"' : '',
            esc_html($label),
            esc_attr($name),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $opts is <option> markup built above with esc_attr()/esc_html().
            $opts,
            $desc !== '' ? '<p class="wpcarve-card-desc">' . esc_html($desc) . '</p>' : '',
        );
    }

    /**
     * @param array<string, mixed> $s
     * @param string $depends
     * @param string $desc
     * @param string $label
     * @param string $key
     */
    private function number(array $s, string $key, string $label, string $desc = '', string $depends = ''): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        printf(
            '<div class="wpcarve-card wpcarve-field"%s>'
            . '<span class="wpcarve-card-label">%s</span>'
            . '<input type="number" name="%s" value="%s" class="small-text">%s</div>',
            $depends !== '' ? ' data-depends="' . esc_attr($depends) . '"' : '',
            esc_html($label),
            esc_attr($name),
            esc_attr((string)($s[$key] ?? 0)),
            $desc !== '' ? '<p class="wpcarve-card-desc">' . esc_html($desc) . '</p>' : '',
        );
    }

    /**
     * @param array<string, mixed> $s
     * @param string $depends
     * @param string $desc
     * @param string $label
     * @param string $key
     */
    private function text(array $s, string $key, string $label, string $desc = '', string $depends = ''): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        printf(
            '<div class="wpcarve-card wpcarve-field"%s>'
            . '<span class="wpcarve-card-label">%s</span>'
            . '<input type="text" name="%s" value="%s" class="regular-text">%s</div>',
            $depends !== '' ? ' data-depends="' . esc_attr($depends) . '"' : '',
            esc_html($label),
            esc_attr($name),
            esc_attr((string)($s[$key] ?? '')),
            $desc !== '' ? '<p class="wpcarve-card-desc">' . esc_html($desc) . '</p>' : '',
        );
    }

    /**
     * @param array<string, mixed> $s
     * @param string $key
     * @param string $label
     * @param string $desc
     */
    private function textarea(array $s, string $key, string $label, string $desc = ''): void
    {
        $name = Settings::OPTION . '[' . $key . ']';
        printf(
            '<div class="wpcarve-card wpcarve-field">'
            . '<span class="wpcarve-card-label">%s</span>'
            . '<textarea name="%s" rows="5" class="large-text code">%s</textarea>%s</div>',
            esc_html($label),
            esc_attr($name),
            esc_textarea((string)($s[$key] ?? '')),
            $desc !== '' ? '<p class="wpcarve-card-desc">' . esc_html($desc) . '</p>' : '',
        );
    }

    /**
     * @param array<string, mixed> $s
     */
    private function diagramGrid(array $s): void
    {
        echo '<div class="wpcarve-grid wpcarve-diagram-grid">';
        foreach (Diagrams::all() as $name => $diagram) {
            $key = Diagrams::settingKey($name);
            $optName = Settings::OPTION . '[' . $key . ']';
            $class = (string)($diagram['class'] ?? $name);
            $weight = $this->libWeight($diagram);
            $preview = $this->previewUrl($name, $diagram);
            $url = !empty($diagram['url']) && is_string($diagram['url']) ? $diagram['url'] : '';
            $label = (string)($diagram['label'] ?? $name);
            $actions = '';
            if ($preview !== '') {
                $actions .= sprintf(
                    '<span class="wpcarve-preview">'
                    . '<span class="wpcarve-icon dashicons dashicons-visibility" tabindex="0" role="button" aria-label="%s"></span>'
                    . '<span class="wpcarve-pop"><img src="%s" alt="" loading="lazy"><code>```%s</code></span></span>',
                    esc_attr(sprintf(/* translators: %s: renderer name */ __('Preview %s output', 'carve-markup'), $label)),
                    esc_url($preview),
                    esc_html($class),
                );
            }
            if ($url !== '') {
                $linkLabel = sprintf(/* translators: %s: renderer name */ __('%s website', 'carve-markup'), $label);
                $actions .= sprintf(
                    '<a class="wpcarve-icon dashicons dashicons-external" href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s" aria-label="%2$s"></a>',
                    esc_url($url),
                    esc_attr($linkLabel),
                );
            }
            $previewHtml = $actions !== '' ? '<span class="wpcarve-actions">' . $actions . '</span>' : '';
            printf(
                '<div class="wpcarve-card wpcarve-diagram">'
                . '<label class="wpcarve-toggle">'
                . '<input type="checkbox" name="%s" value="1" %s>'
                . '<span class="wpcarve-switch" aria-hidden="true"></span>'
                . '<span class="wpcarve-card-label">%s</span></label>'
                . '<p class="wpcarve-card-desc"><code>```%s</code></p>'
                . '<span class="wpcarve-card-foot"><span class="wpcarve-badge">%s</span>%s</span>'
                . '</div>',
                esc_attr($optName),
                checked(!empty($s[$key]), true, false),
                esc_html((string)($diagram['label'] ?? $name)),
                esc_html($class),
                esc_html($weight),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $previewHtml is anchor markup built above with esc_url()/esc_attr().
                $previewHtml,
            );
        }
        echo '</div>';
    }

    /**
     * Preview image URL for a renderer: an explicit `preview` entry (custom
     * renderers), else a bundled thumbnail named after the registry key.
     *
     * @param string $name
     * @param array<string, mixed> $diagram
     */
    private function previewUrl(string $name, array $diagram): string
    {
        if (!empty($diagram['preview']) && is_string($diagram['preview'])) {
            return $diagram['preview'];
        }
        foreach (['svg', 'png'] as $ext) {
            $rel = 'assets/img/diagrams/' . $name . '.' . $ext;
            if (is_file(WPCARVE_DIR . $rel)) {
                return WPCARVE_URL . $rel;
            }
        }

        return '';
    }

    /**
     * Human-readable size of a renderer's vendored libraries.
     *
     * @param array<string, mixed> $diagram
     */
    private function libWeight(array $diagram): string
    {
        if (!empty($diagram['src'])) {
            return __('external', 'carve-markup');
        }
        $bytes = 0;
        foreach ((array)($diagram['libs'] ?? []) as $lib) {
            $path = WPCARVE_DIR . 'assets/js/vendor/' . $lib;
            if (is_string($lib) && is_file($path)) {
                $bytes += (int)filesize($path);
            }
        }
        if ($bytes <= 0) {
            return '';
        }
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }

        return sprintf('%d KB', (int)round($bytes / 1024));
    }
}
