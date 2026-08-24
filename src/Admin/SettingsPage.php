<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Admin;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Cache\Invalidation;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\FileStore;

use const Pollora\AiVisibility\OPTION_KEY;

/**
 * Admin settings page under Settings > AI Visibility.
 */
final class SettingsPage
{
    public const SLUG = 'ai-visibility';

    private const NONCE_ACTION = 'ai_visibility_regenerate';

    public function register(): void
    {
        add_options_page(
            __('AI Visibility', 'ai-visibility'),
            __('AI Visibility', 'ai-visibility'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::SLUG, OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => Plugin::defaultSettings(),
        ]);

        // Section: Features
        $this->addSection('features', __('Features', 'ai-visibility'));

        $this->addCheckbox('enable_llms_txt', __('Enable llms.txt / llms-full.txt', 'ai-visibility'), 'features');
        $this->addCheckbox('enable_markdown', __('Enable Markdown endpoints (.md and ?format=md)', 'ai-visibility'), 'features');
        $this->addCheckbox('enable_robots', __('Enable robots.txt AI directives', 'ai-visibility'), 'features');
        $this->addCheckbox('enable_discovery', __('Enable AI discovery files (.well-known/)', 'ai-visibility'), 'features');
        $this->addCheckbox('enable_abilities', __('Enable MCP Abilities (WP 6.9+)', 'ai-visibility'), 'features');

        // Section: Content
        $this->addSection('content', __('Content', 'ai-visibility'));

        add_settings_field('site_description', __('Site description for AI', 'ai-visibility'), [$this, 'renderSiteDescription'], self::SLUG, 'content');
        add_settings_field('post_types', __('Post types to include', 'ai-visibility'), [$this, 'renderPostTypes'], self::SLUG, 'content');
        add_settings_field('posts_per_type', __('Posts per type', 'ai-visibility'), [$this, 'renderPostsPerType'], self::SLUG, 'content');

        // Section: AI Crawlers
        $this->addSection('crawlers', __('AI Crawlers', 'ai-visibility'));

        add_settings_field('crawlers_allow', __('Allowed crawlers', 'ai-visibility'), fn () => $this->renderCrawlerList('crawlers_allow'), self::SLUG, 'crawlers');
        add_settings_field('crawlers_block', __('Blocked crawlers', 'ai-visibility'), fn () => $this->renderCrawlerList('crawlers_block'), self::SLUG, 'crawlers');

        // Section: Identity
        $this->addSection('identity', __('Site Identity (for AI)', 'ai-visibility'));

        add_settings_field('identity_email', __('Contact email', 'ai-visibility'), [$this, 'renderEmail'], self::SLUG, 'identity');
        add_settings_field('identity_socials', __('Social links', 'ai-visibility'), [$this, 'renderSocials'], self::SLUG, 'identity');
    }

    /**
     * Turn whatever the settings form posted into the canonical settings shape.
     *
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            return Plugin::defaultSettings();
        }

        return Plugin::normalize([
            'enable_llms_txt' => !empty($input['enable_llms_txt']),
            'enable_markdown' => !empty($input['enable_markdown']),
            'enable_robots' => !empty($input['enable_robots']),
            'enable_discovery' => !empty($input['enable_discovery']),
            'enable_abilities' => !empty($input['enable_abilities']),
            'post_types' => self::sanitizePostTypes($input['post_types'] ?? []),
            'site_description' => sanitize_textarea_field(self::asString($input['site_description'] ?? '')),
            'posts_per_type' => $input['posts_per_type'] ?? Plugin::defaultSettings()['posts_per_type'],
            'crawlers_allow' => self::sanitizeCrawlerList($input['crawlers_allow'] ?? ''),
            'crawlers_block' => self::sanitizeCrawlerList($input['crawlers_block'] ?? ''),
            'identity_email' => sanitize_email(self::asString($input['identity_email'] ?? '')),
            'identity_socials' => self::sanitizeSocialLinks($input['identity_socials'] ?? ''),
        ]);
    }

    public function render(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Visibility', 'ai-visibility'); ?></h1>

            <form method="post" action="options.php">
                <?php $this->renderForm(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Generated Files', 'ai-visibility'); ?></h2>

            <?php $this->renderFilePreview(); ?>

            <p>
                <button type="button" class="button button-secondary" id="ai-visibility-regenerate">
                    <?php esc_html_e('Regenerate All Files', 'ai-visibility'); ?>
                </button>
                <span id="ai-visibility-status" role="status" aria-live="polite"></span>
            </p>
        </div>
        <?php
    }

    public function handleRegenerate(): void
    {
        // Capability first: an unprivileged user should be told they may not do
        // this, not that their nonce expired.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to regenerate these files.', 'ai-visibility')], 403);
        }

        check_ajax_referer(self::NONCE_ACTION);

        $failed = (new Invalidation())->regenerateAll();

        if ($failed !== []) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: comma-separated list of file names. */
                    __('Could not write: %s. Check that the uploads directory is writable.', 'ai-visibility'),
                    implode(', ', $failed),
                ),
            ], 500);
        }

        wp_send_json_success([
            'message' => __('All files regenerated.', 'ai-visibility'),
            'generated' => wp_date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Enqueue the regeneration script, on this settings screen only.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::SLUG) {
            return;
        }

        wp_enqueue_script(
            'ai-visibility-admin',
            plugins_url('assets/admin.js', \Pollora\AiVisibility\PLUGIN_FILE),
            [],
            \Pollora\AiVisibility\VERSION,
            true,
        );

        wp_localize_script('ai-visibility-admin', 'aiVisibilityAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'action' => self::NONCE_ACTION,
            'working' => __('Regenerating…', 'ai-visibility'),
            'failed' => __('Regeneration failed.', 'ai-visibility'),
        ]);
    }

    // -- Field renderers --

    public function renderSiteDescription(): void
    {
        $settings = Plugin::settings();
        printf(
            '<textarea name="%s[site_description]" rows="4" cols="60" class="large-text">%s</textarea>',
            esc_attr(OPTION_KEY),
            esc_textarea($settings['site_description']),
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Describe your site for AI engines: what it is, who it serves, what topics it covers. This text appears at the top of llms.txt, ai.txt and identity.json.', 'ai-visibility'),
        );
    }

    public function renderPostTypes(): void
    {
        $selected = Plugin::settings()['post_types'];

        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }

            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[post_types][]" value="%s" %s> %s (<code>%s</code>)</label>',
                esc_attr(OPTION_KEY),
                esc_attr($type->name),
                checked(in_array($type->name, $selected, true), true, false),
                esc_html(self::typeLabel($type)),
                esc_html($type->name),
            );
        }
    }

    public function renderPostsPerType(): void
    {
        printf(
            '<input type="number" name="%s[posts_per_type]" value="%d" min="%d" max="%d" class="small-text">',
            esc_attr(OPTION_KEY),
            (int) Plugin::settings()['posts_per_type'],
            (int) Plugin::MIN_POSTS_PER_TYPE,
            (int) Plugin::MAX_POSTS_PER_TYPE,
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('Maximum number of posts per post type in llms.txt', 'ai-visibility'),
        );
    }

    public function renderEmail(): void
    {
        $settings = Plugin::settings();
        $value = $settings['identity_email'];

        if ($value === '') {
            $adminEmail = get_option('admin_email');
            $value = is_string($adminEmail) ? $adminEmail : '';
        }

        printf(
            '<input type="email" name="%s[identity_email]" value="%s" class="regular-text">',
            esc_attr(OPTION_KEY),
            esc_attr($value),
        );
    }

    public function renderSocials(): void
    {
        printf(
            '<textarea name="%s[identity_socials]" rows="4" cols="50" class="large-text code">%s</textarea>',
            esc_attr(OPTION_KEY),
            esc_textarea(implode("\n", Plugin::settings()['identity_socials'])),
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('One URL per line (Facebook, Twitter, Instagram…)', 'ai-visibility'),
        );
    }

    // -- Internals --

    private function renderForm(): void
    {
        settings_fields(self::SLUG);
        do_settings_sections(self::SLUG);
        submit_button();
    }

    private function addSection(string $id, string $title): void
    {
        add_settings_section($id, $title, static function (): void {
        }, self::SLUG);
    }

    private function addCheckbox(string $key, string $label, string $section): void
    {
        add_settings_field(
            $key,
            $label,
            static function () use ($key): void {
                $settings = Plugin::settings();
                printf(
                    '<input type="checkbox" name="%s[%s]" value="1" %s>',
                    esc_attr(OPTION_KEY),
                    esc_attr($key),
                    checked(!empty($settings[$key]), true, false),
                );
            },
            self::SLUG,
            $section,
        );
    }

    /**
     * @param  'crawlers_allow'|'crawlers_block'  $key
     */
    private function renderCrawlerList(string $key): void
    {
        printf(
            '<textarea name="%s[%s]" rows="6" cols="40" class="large-text code">%s</textarea>',
            esc_attr(OPTION_KEY),
            esc_attr($key),
            esc_textarea(implode("\n", Plugin::settings()[$key])),
        );
        printf(
            '<p class="description">%s</p>',
            esc_html__('One crawler name per line', 'ai-visibility'),
        );
    }

    private function renderFilePreview(): void
    {
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';

        foreach (Artifact::cases() as $artifact) {
            $contents = FileStore::read($artifact)
                ?? __('Not yet generated. Click "Regenerate All Files".', 'ai-visibility');

            printf(
                '<div><h3><a href="%s" target="_blank" rel="noopener">%s</a></h3>'
                . '<textarea readonly rows="10" class="large-text code" style="font-size:12px;">%s</textarea></div>',
                esc_url($artifact->url()),
                esc_html($artifact->value),
                esc_textarea($contents),
            );
        }

        echo '</div>';
    }

    /**
     * `labels` is a plain stdClass built by core and third parties alike, so
     * nothing guarantees `name` is there.
     */
    private static function typeLabel(\WP_Post_Type $type): string
    {
        $label = $type->labels->name ?? $type->label;

        return is_string($label) && $label !== '' ? $label : $type->name;
    }

    /**
     * @return list<string>
     */
    private static function sanitizePostTypes(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $types = [];

        foreach ($input as $type) {
            if (is_string($type) && post_type_exists($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Accepts either the textarea (one per line) or an already-split array.
     *
     * @return list<string>
     */
    private static function sanitizeCrawlerList(mixed $input): array
    {
        $values = is_array($input)
            ? $input
            : explode("\n", sanitize_textarea_field(self::asString($input)));

        $names = [];

        foreach ($values as $value) {
            $name = sanitize_text_field(trim(self::asString($value)));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function sanitizeSocialLinks(mixed $input): array
    {
        if (!is_string($input)) {
            return [];
        }

        $urls = [];

        foreach (explode("\n", $input) as $line) {
            $url = esc_url_raw(trim($line));

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
