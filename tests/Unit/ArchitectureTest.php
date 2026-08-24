<?php

declare(strict_types=1);

/**
 * Structural rules. These catch the class of mistake that compiles, passes the
 * unit tests, and is only noticed once the plugin is on somebody else's site.
 */
arch('every source file declares strict types')
    ->expect('Pollora\AiVisibility')
    ->toUseStrictTypes();

arch('classes are final unless there is a reason not to be')
    ->expect('Pollora\AiVisibility')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        // WP-CLI instantiates and reflects over the command class itself.
        'Pollora\AiVisibility\CLI\GenerateCommand',
    ]);

arch('generators depend on no other layer')
    ->expect('Pollora\AiVisibility\Generator')
    ->not->toUse([
        'Pollora\AiVisibility\Admin',
        'Pollora\AiVisibility\Cache',
        'Pollora\AiVisibility\CLI',
    ]);

arch('the admin screen is never loaded on the front end path')
    ->expect('Pollora\AiVisibility\Admin')
    ->not->toBeUsedIn([
        'Pollora\AiVisibility\Endpoint',
        'Pollora\AiVisibility\Generator',
        'Pollora\AiVisibility\RobotsTxt',
    ]);

arch('support helpers stay dependency-free')
    ->expect('Pollora\AiVisibility\Support\Filters')
    ->not->toUse([
        'Pollora\AiVisibility\Admin',
        'Pollora\AiVisibility\Endpoint',
        'Pollora\AiVisibility\Generator',
    ]);

arch('nothing debugs in production code')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'error_log', 'phpinfo'])
    ->not->toBeUsed();

arch('no raw SQL outside the uninstaller')
    ->expect(['mysqli_query', 'mysql_query'])
    ->not->toBeUsed();

arch('no dynamic code execution')
    ->expect(['eval', 'create_function', 'assert', 'extract'])
    ->not->toBeUsed();

arch('no unserialize on anything that could come from a request')
    ->expect('unserialize')
    ->not->toBeUsed();

arch('no outbound HTTP: the plugin describes the site, it does not call home')
    ->expect(['curl_init', 'file_get_contents', 'fsockopen', 'wp_remote_get', 'wp_remote_post'])
    ->not->toBeUsed()
    ->ignoring([
        // FileStore reads the plugin's own cache files from disk.
        'Pollora\AiVisibility\Support\FileStore',
    ]);
