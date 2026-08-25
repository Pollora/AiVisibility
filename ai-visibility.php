<?php
/**
 * Plugin Name: AI Visibility
 * Description: Maximize site visibility for AI engines — llms.txt, Markdown endpoints, robots.txt AI directives, AI discovery files, and MCP abilities.
 * Version: 1.1.0
 * Author: Pollora
 * Author URI: https://pollora.com
 * Requires PHP: 8.3
 * Requires at least: 6.7
 * License: GPL-2.0-or-later
 * Text Domain: ai-visibility
 * Domain Path: /languages
 */

declare(strict_types=1);

namespace Pollora\AiVisibility;

defined('ABSPATH') || exit;

const VERSION = '1.1.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR = __DIR__;
const OPTION_KEY = 'ai_visibility_settings';
const UPLOAD_DIR = 'ai-visibility';
const CRON_HOOK = 'ai_visibility_regenerate';

require_once __DIR__ . '/vendor/autoload.php';

add_action('plugins_loaded', static function (): void {
    (new Plugin())->boot();
});

// On `init`, not earlier: since WordPress 6.7, loading a text domain before
// then triggers a _load_textdomain_just_in_time notice. Sites installing from
// wordpress.org get their translations automatically; this covers the ones
// installing the zip or via Composer.
add_action('init', static function (): void {
    load_plugin_textdomain('ai-visibility', false, dirname(plugin_basename(__FILE__)) . '/languages');
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
