<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Admin;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\FileStore;

/**
 * The checks the dashboard reports on.
 *
 * Every one of these has silently broken a real installation. Plain permalinks
 * make every endpoint 404 with no error anywhere; a read-only uploads directory
 * makes regeneration a no-op; a site marked "discourage search engines" makes
 * the robots.txt directives disappear. None of it is visible from the settings
 * form, so the screen says so out loud instead.
 */
final class Diagnostics
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return list<array{id: string, status: self::*, label: string, detail: string}>
     */
    public static function all(): array
    {
        return [
            self::permalinks(),
            self::uploads(),
            self::visibility(),
            self::files(),
            self::abilities(),
        ];
    }

    /**
     * The single worst status across every check, for the masthead pill.
     *
     * @return self::*
     */
    public static function worst(): string
    {
        $statuses = array_column(self::all(), 'status');

        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }

        return in_array(self::WARN, $statuses, true) ? self::WARN : self::PASS;
    }

    /**
     * Without a permalink structure WordPress cannot route /llms.txt at all.
     *
     * @return array{id: string, status: self::*, label: string, detail: string}
     */
    private static function permalinks(): array
    {
        $structure = get_option('permalink_structure');
        $pretty = is_string($structure) && $structure !== '';

        return [
            'id' => 'permalinks',
            'status' => $pretty ? self::PASS : self::FAIL,
            'label' => __('Pretty permalinks', 'amphibee-ai-visibility'),
            'detail' => $pretty
                ? __('Rewrite rules can resolve the endpoints.', 'amphibee-ai-visibility')
                : __('Set to anything but "Plain" under Settings → Permalinks, or every endpoint answers 404.', 'amphibee-ai-visibility'),
        ];
    }

    /**
     * @return array{id: string, status: self::*, label: string, detail: string}
     */
    private static function uploads(): array
    {
        $directory = Plugin::uploadDir();

        return [
            'id' => 'uploads',
            'status' => $directory !== null && is_writable($directory) ? self::PASS : self::FAIL,
            'label' => __('Writable cache directory', 'amphibee-ai-visibility'),
            'detail' => $directory !== null && is_writable($directory)
                ? __('Generated files are stored in the uploads folder.', 'amphibee-ai-visibility')
                : __('The uploads folder is not writable, so regeneration cannot store anything.', 'amphibee-ai-visibility'),
        ];
    }

    /**
     * @return array{id: string, status: self::*, label: string, detail: string}
     */
    private static function visibility(): array
    {
        $public = (bool) get_option('blog_public');

        return [
            'id' => 'visibility',
            'status' => $public ? self::PASS : self::WARN,
            'label' => __('Site visible to crawlers', 'amphibee-ai-visibility'),
            'detail' => $public
                ? __('robots.txt carries the AI crawler directives.', 'amphibee-ai-visibility')
                : __('"Discourage search engines" is on, so the AI directives are withheld from robots.txt.', 'amphibee-ai-visibility'),
        ];
    }

    /**
     * @return array{id: string, status: self::*, label: string, detail: string}
     */
    private static function files(): array
    {
        $missing = [];

        foreach (Artifact::cases() as $artifact) {
            if (FileStore::stat($artifact) === null) {
                $missing[] = $artifact->value;
            }
        }

        return [
            'id' => 'files',
            'status' => $missing === [] ? self::PASS : self::WARN,
            'label' => __('Files generated', 'amphibee-ai-visibility'),
            'detail' => $missing === []
                ? __('All four files are on disk.', 'amphibee-ai-visibility')
                : sprintf(
                    /* translators: %s: comma-separated list of file names. */
                    __('Not generated yet: %s. They are built on first request, or now with the button above.', 'amphibee-ai-visibility'),
                    implode(', ', $missing),
                ),
        ];
    }

    /**
     * @return array{id: string, status: self::*, label: string, detail: string}
     */
    private static function abilities(): array
    {
        $available = function_exists('wp_register_ability');

        return [
            'id' => 'abilities',
            'status' => $available ? self::PASS : self::WARN,
            'label' => __('Abilities API', 'amphibee-ai-visibility'),
            'detail' => $available
                ? __('MCP clients can call this site through the Abilities API.', 'amphibee-ai-visibility')
                : __('Requires WordPress 6.9 or newer. Everything else works without it.', 'amphibee-ai-visibility'),
        ];
    }
}
