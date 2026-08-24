<?php
/**
 * Removes every trace of the plugin when it is deleted from the admin.
 *
 * Deactivation is reversible and must leave data alone; deletion is not.
 * WordPress loads this file instead of the plugin itself, so nothing from
 * the plugin's own namespace is available here.
 *
 * @package Pollora\AiVisibility
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Deletes the plugin's options, scheduled events, transients and cache files
 * for the current site.
 */
function ai_visibility_uninstall_site(): void
{
    delete_option('ai_visibility_settings');
    delete_option('ai_visibility_last_generated');
    delete_option('ai_visibility_flush_rewrite');

    wp_clear_scheduled_hook('ai_visibility_regenerate');

    ai_visibility_uninstall_delete_transients();
    ai_visibility_uninstall_delete_files();
}

/**
 * Deletes the per-post Markdown transients.
 *
 * Walks post IDs rather than sweeping the options table with a LIKE: the keys
 * are derived from the ID, so this stays exact, and it works the same whether
 * transients live in the database or in a persistent object cache — where a
 * LIKE query would find nothing at all.
 */
function ai_visibility_uninstall_delete_transients(): void
{
    $page = 1;

    do {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 500,
            'paged' => $page,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                delete_transient('ai_vis_md_' . (int) $id);
            }
        }

        $page++;
    } while (count($ids) === 500);
}

/**
 * Deletes the generated llms.txt / ai.txt / identity.json cache directory.
 */
function ai_visibility_uninstall_delete_files(): void
{
    $uploads = wp_upload_dir();

    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return;
    }

    $dir = $uploads['basedir'] . '/ai-visibility';

    if (!is_dir($dir)) {
        return;
    }

    foreach (['llms.txt', 'llms-full.txt', 'ai.txt', 'identity.json', 'index.html'] as $file) {
        $path = $dir . '/' . $file;

        if (is_file($path)) {
            wp_delete_file($path);
        }
    }

    // Only removes the directory when nothing else was put there.
    @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

if (is_multisite()) {
    $ai_visibility_sites = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($ai_visibility_sites as $ai_visibility_site_id) {
        switch_to_blog((int) $ai_visibility_site_id);
        ai_visibility_uninstall_site();
        restore_current_blog();
    }

    unset($ai_visibility_sites, $ai_visibility_site_id);
} else {
    ai_visibility_uninstall_site();
}
