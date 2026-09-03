<?php
/**
 * Plugin Name: AmphiBee AI Visibility
 * Description: Maximize site visibility for AI engines — llms.txt, Markdown endpoints, robots.txt AI directives, AI discovery files, and MCP abilities.
 * Version: 1.3.3
 * Author: AmphiBee
 * Author URI: https://amphibee.fr
 * Requires PHP: 8.3
 * Requires at least: 6.7
 * License: GPL-2.0-or-later
 * Text Domain: amphibee-ai-visibility
 * Domain Path: /languages
 */

declare(strict_types=1);

namespace Pollora\AiVisibility;

defined('ABSPATH') || exit;

const VERSION = '1.3.3';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR = __DIR__;
const OPTION_KEY = 'ai_visibility_settings';
const UPLOAD_DIR = 'ai-visibility';
const CRON_HOOK = 'ai_visibility_regenerate';

// Only the release zip carries a vendor/ directory. A Composer install has
// none, and does not need one: the package's PSR-4 mapping is already in the
// consuming project's autoloader. Requiring the file unconditionally made
// every Composer install fatal the moment it was activated.
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Neither source produced the classes — a zip built without its dependencies,
// or a project whose autoloader is not loaded. Say so where someone can read
// it instead of fataling on the next line.
if (!class_exists(Plugin::class)) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'AI Visibility cannot find its classes. Install the plugin with Composer, or from a release zip.',
                'amphibee-ai-visibility',
            ),
        );
    });

    return;
}

add_action('plugins_loaded', static function (): void {
    (new Plugin())->boot();
});

// On `init`, not earlier: since WordPress 6.7, loading a text domain before
// then triggers a _load_textdomain_just_in_time notice. Sites installing from
// wordpress.org get their translations automatically; this covers the ones
// installing the zip or via Composer.
add_action('init', static function (): void {
    load_plugin_textdomain('amphibee-ai-visibility', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

if (defined('WP_CLI') && \WP_CLI) {
    require_once __DIR__ . '/cli/GenerateCommand.php';
}

register_activation_hook(__FILE__, static function (): void {
    update_option('ai_visibility_flush_rewrite', true);

    if (!get_option(OPTION_KEY)) {
        update_option(OPTION_KEY, Plugin::defaultSettings());
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(CRON_HOOK);

    // Not flush_rewrite_rules(): the plugin is still loaded during the request
    // that deactivates it, so a flush here re-registers the very rules it is
    // meant to remove. /llms.txt then keeps matching a rule whose query var no
    // longer exists, and WordPress answers with a canonical 301 instead of a
    // 404. Dropping the cached rules makes the next request rebuild them
    // without this plugin.
    delete_option('rewrite_rules');
});
