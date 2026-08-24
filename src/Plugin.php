<?php

declare(strict_types=1);

namespace Pollora\AiVisibility;

use Pollora\AiVisibility\Admin\SettingsPage;
use Pollora\AiVisibility\Cache\Invalidation;
use Pollora\AiVisibility\Endpoint\DiscoveryFilesEndpoint;
use Pollora\AiVisibility\Endpoint\LlmsTxtEndpoint;
use Pollora\AiVisibility\Endpoint\MarkdownEndpoint;
use Pollora\AiVisibility\RobotsTxt\AiDirectives;

/**
 * @phpstan-type AiVisibilitySettings array{
 *     enable_llms_txt: bool,
 *     enable_markdown: bool,
 *     enable_robots: bool,
 *     enable_discovery: bool,
 *     enable_abilities: bool,
 *     site_description: string,
 *     post_types: list<string>,
 *     posts_per_type: int<1, 200>,
 *     crawlers_allow: list<string>,
 *     crawlers_block: list<string>,
 *     identity_email: string,
 *     identity_socials: list<string>,
 * }
 */
final class Plugin
{
    /** Bounds for posts_per_type, enforced on read as well as on save. */
    public const MIN_POSTS_PER_TYPE = 1;

    public const MAX_POSTS_PER_TYPE = 200;

    public function boot(): void
    {
        $settings = self::settings();

        // Endpoints
        if ($settings['enable_llms_txt']) {
            $llmsEndpoint = new LlmsTxtEndpoint();
            add_action('init', [$llmsEndpoint, 'registerRewriteRules']);
            add_action('template_redirect', [$llmsEndpoint, 'handleRequest']);
        }

        if ($settings['enable_markdown']) {
            $markdownEndpoint = new MarkdownEndpoint();
            add_action('init', [$markdownEndpoint, 'registerRewriteRules']);
            add_action('init', [$markdownEndpoint, 'registerQueryVars']);
            add_action('template_redirect', [$markdownEndpoint, 'handleRequest']);
        }

        if ($settings['enable_discovery']) {
            $discoveryEndpoint = new DiscoveryFilesEndpoint();
            add_action('init', [$discoveryEndpoint, 'registerRewriteRules']);
            add_action('template_redirect', [$discoveryEndpoint, 'handleRequest']);
        }

        // Robots.txt
        if ($settings['enable_robots']) {
            $aiDirectives = new AiDirectives();
            add_filter('robots_txt', [$aiDirectives, 'addDirectives'], 20, 2);
        }

        // Cache invalidation
        $invalidation = new Invalidation();
        add_action('save_post', [$invalidation, 'scheduleRegeneration'], 20, 2);
        add_action('delete_post', [$invalidation, 'scheduleRegeneration'], 20);
        add_action(CRON_HOOK, [$invalidation, 'regenerate']);

        // Admin
        if (is_admin()) {
            $settingsPage = new SettingsPage();
            add_action('admin_menu', [$settingsPage, 'register']);
            add_action('admin_init', [$settingsPage, 'registerSettings']);
            add_action('admin_enqueue_scripts', [$settingsPage, 'enqueueAssets']);
            add_action('wp_ajax_ai_visibility_regenerate', [$settingsPage, 'handleRegenerate']);
        }

        // Abilities API (WP 6.9+)
        if ($settings['enable_abilities'] && function_exists('wp_register_ability')) {
            $abilities = new Abilities\Registration();
            add_action('wp_abilities_api_categories_init', [$abilities, 'registerCategories']);
            add_action('wp_abilities_api_init', [$abilities, 'registerAbilities']);
        }

        // Prevent WordPress trailing slash redirect on our file-like URLs
        add_filter('redirect_canonical', [$this, 'preventTrailingSlash'], 10, 2);

        // Flush rewrite rules once after activation
        add_action('init', [$this, 'maybeFlushRewrites'], 99);
    }

    /**
     * Disable WordPress canonical trailing slash redirect for plugin endpoints.
     * Without this, /llms.txt is 301'd to /llms.txt/ which breaks the spec.
     */
    public function preventTrailingSlash(string|false $redirect, string $requested): string|false
    {
        $patterns = [
            '#/llms\.txt$#',
            '#/llms-full\.txt$#',
            '#/ai-discovery/ai\.txt$#',
            '#/ai-discovery/identity\.json$#',
            '#/\.well-known/ai\.txt$#',
            '#/\.well-known/identity\.json$#',
            '#\.md$#',
        ];

        $path = wp_parse_url($requested, PHP_URL_PATH);

        if (!is_string($path)) {
            return $redirect;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }

        return $redirect;
    }

    public function maybeFlushRewrites(): void
    {
        if (get_option('ai_visibility_flush_rewrite')) {
            flush_rewrite_rules();
            delete_option('ai_visibility_flush_rewrite');
        }
    }

    /**
     * The plugin's effective settings, always in a known shape.
     *
     * Anything may end up in the option row — a half-written update, a stale
     * value from an older version, a migration script. Normalising on read
     * means no consumer has to guess, and a corrupt row degrades to defaults
     * instead of a fatal.
     *
     * @return AiVisibilitySettings
     */
    public static function settings(): array
    {
        $stored = get_option(OPTION_KEY, []);

        return self::normalize(is_array($stored) ? $stored : []);
    }

    /**
     * Coerce an arbitrary array into the settings shape, filling gaps with defaults.
     *
     * @param  array<mixed>  $stored
     * @return AiVisibilitySettings
     */
    public static function normalize(array $stored): array
    {
        $defaults = self::defaultSettings();

        return [
            'enable_llms_txt' => self::bool($stored, 'enable_llms_txt', $defaults['enable_llms_txt']),
            'enable_markdown' => self::bool($stored, 'enable_markdown', $defaults['enable_markdown']),
            'enable_robots' => self::bool($stored, 'enable_robots', $defaults['enable_robots']),
            'enable_discovery' => self::bool($stored, 'enable_discovery', $defaults['enable_discovery']),
            'enable_abilities' => self::bool($stored, 'enable_abilities', $defaults['enable_abilities']),
            'site_description' => self::string($stored, 'site_description', $defaults['site_description']),
            'post_types' => self::stringList($stored, 'post_types', $defaults['post_types']),
            'posts_per_type' => self::postsPerType($stored['posts_per_type'] ?? $defaults['posts_per_type']),
            'crawlers_allow' => self::stringList($stored, 'crawlers_allow', $defaults['crawlers_allow']),
            'crawlers_block' => self::stringList($stored, 'crawlers_block', $defaults['crawlers_block']),
            'identity_email' => self::string($stored, 'identity_email', $defaults['identity_email']),
            'identity_socials' => self::stringList($stored, 'identity_socials', $defaults['identity_socials']),
        ];
    }

    /**
     * @return AiVisibilitySettings
     */
    public static function defaultSettings(): array
    {
        return [
            'enable_llms_txt' => true,
            'enable_markdown' => true,
            'enable_robots' => true,
            'enable_discovery' => true,
            'enable_abilities' => true,
            'site_description' => '',
            'post_types' => ['post', 'page'],
            'posts_per_type' => 50,
            'crawlers_allow' => [
                'GPTBot',
                'OAI-SearchBot',
                'ChatGPT-User',
                'ClaudeBot',
                'Claude-SearchBot',
                'Claude-User',
                'PerplexityBot',
                'Perplexity-User',
                'Google-Extended',
                'Amazonbot',
            ],
            'crawlers_block' => [
                'CCBot',
                'Bytespider',
            ],
            'identity_email' => '',
            'identity_socials' => [],
        ];
    }

    /**
     * Absolute path to the directory holding the generated files.
     *
     * Returns null when the uploads directory is unusable (read-only mount,
     * disk full, filtered to an invalid path) so callers can fall back to
     * generating on the fly rather than writing into the void.
     */
    public static function uploadDir(): ?string
    {
        $uploads = wp_upload_dir();

        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }

        $dir = $uploads['basedir'] . '/' . UPLOAD_DIR;

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return null;
        }

        return $dir;
    }

    /**
     * Absolute path of one generated file, or null when it cannot be located.
     */
    public static function filePath(string $filename): ?string
    {
        $dir = self::uploadDir();

        return $dir === null ? null : $dir . '/' . $filename;
    }

    /**
     * @param  array<mixed>  $stored
     */
    private static function bool(array $stored, string $key, bool $default): bool
    {
        return array_key_exists($key, $stored) ? (bool) $stored[$key] : $default;
    }

    /**
     * @param  array<mixed>  $stored
     */
    private static function string(array $stored, string $key, string $default): string
    {
        $value = $stored[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<mixed>  $stored
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(array $stored, string $key, array $default): array
    {
        $value = $stored[$key] ?? null;

        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return int<1, 200>
     */
    private static function postsPerType(mixed $value): int
    {
        $count = is_numeric($value) ? (int) $value : 50;

        return max(self::MIN_POSTS_PER_TYPE, min(self::MAX_POSTS_PER_TYPE, $count));
    }
}
