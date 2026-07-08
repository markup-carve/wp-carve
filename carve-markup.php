<?php

declare(strict_types=1);

use WpCarve\Plugin;

/**
 * Plugin Name: Carve Markup
 * Plugin URI: https://github.com/markup-carve/wp-carve
 * Description: Write posts, pages and comments in the Carve markup language. Live in-browser preview, multi-format paste, frontmatter-to-meta, render caching and a REST API.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Mark Scherer
 * Author URI: https://www.dereuromark.de
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: carve-markup
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPCARVE_VERSION', '0.1.1');
define('WPCARVE_FILE', __FILE__);
define('WPCARVE_DIR', plugin_dir_path(__FILE__));
define('WPCARVE_URL', plugin_dir_url(__FILE__));

$wpCarveAutoload = WPCARVE_DIR . 'vendor/autoload.php';
if (is_readable($wpCarveAutoload)) {
    require $wpCarveAutoload;
}

if (!class_exists(Plugin::class)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Carve Markup: run "composer install" in the plugin directory (the carve-php engine is missing).', 'carve-markup');
        echo '</p></div>';
    });

    return;
}

add_action('plugins_loaded', static function (): void {
    (new Plugin())->boot();
});
