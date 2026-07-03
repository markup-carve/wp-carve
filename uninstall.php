<?php

declare(strict_types=1);

// Fired by WordPress when the plugin is deleted.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpcarve_settings');

// Remove plugin post meta across all posts. One-time uninstall cleanup: a direct
// query is the correct tool (no per-row hooks needed) and caching is irrelevant.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup.
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wpcarve_enabled', '_wpcarve_html', '_wpcarve_html_version', '_wpcarve_frontmatter', '_wpcarve_excerpt', '_wpcarve_seo_description', '_wpcarve_canonical')",
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup.
$wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key = '_wpcarve_raw'");
