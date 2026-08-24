<?php
/**
 * The namespaced constants ai-visibility.php declares.
 *
 * The plugin file itself cannot be loaded in unit tests: it registers hooks at
 * file scope, before Brain Monkey is up. DistributionTest asserts these values
 * still match the ones in ai-visibility.php, so the duplication cannot drift.
 *
 * @package Pollora\AiVisibility
 */

declare(strict_types=1);

namespace Pollora\AiVisibility;

defined(__NAMESPACE__ . '\VERSION') || define(__NAMESPACE__ . '\VERSION', '1.0.0');
defined(__NAMESPACE__ . '\PLUGIN_FILE') || define(__NAMESPACE__ . '\PLUGIN_FILE', dirname(__DIR__, 2) . '/ai-visibility.php');
defined(__NAMESPACE__ . '\PLUGIN_DIR') || define(__NAMESPACE__ . '\PLUGIN_DIR', dirname(__DIR__, 2));
defined(__NAMESPACE__ . '\OPTION_KEY') || define(__NAMESPACE__ . '\OPTION_KEY', 'ai_visibility_settings');
defined(__NAMESPACE__ . '\UPLOAD_DIR') || define(__NAMESPACE__ . '\UPLOAD_DIR', 'ai-visibility');
defined(__NAMESPACE__ . '\CRON_HOOK') || define(__NAMESPACE__ . '\CRON_HOOK', 'ai_visibility_regenerate');
