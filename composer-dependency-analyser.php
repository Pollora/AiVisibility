<?php

declare(strict_types=1);

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this plugin.
 */
$configuration = require __DIR__ . '/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // The plugin's constant, defined in ai-visibility.php before the autoloader runs, and the WP namespace alias.
    ->ignoreUnknownClasses(['Pollora\AiVisibility\PLUGIN_FILE', 'WP']);
