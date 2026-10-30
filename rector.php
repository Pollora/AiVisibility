<?php

declare(strict_types=1);

use Pollora\Harness\Profile;
use Pollora\Harness\Rector\Preset;

return Preset::configure(__DIR__, Profile::WpPlugin)
    // Every file of the plugin starts with defined('ABSPATH') || exit;: Rector's workers die on it unless ABSPATH exists.
    ->withBootstrapFiles([__DIR__ . '/tests/bootstrap.php'])
    ->withSkip([
        // Each skip says why, like the framework's rector.php.
    ]);
