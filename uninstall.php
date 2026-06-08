<?php

declare(strict_types=1);

// Fired by WordPress when the plugin is deleted.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wp_carve_settings');

// Remove plugin post meta across all posts.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_carve_enabled', '_wp_carve_html', '_wp_carve_html_version', '_wp_carve_frontmatter', '_wp_carve_excerpt', '_wp_carve_seo_description', '_wp_carve_canonical')",
);
$wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key = '_wp_carve_raw'");
