<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Admin;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Cache\Invalidation;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\FileStore;

use const Pollora\AiVisibility\OPTION_KEY;
use const Pollora\AiVisibility\VERSION;

/**
 * Admin settings screen under Settings → AI Visibility.
 *
 * The screen is rendered directly rather than through do_settings_sections():
 * the Settings API can only produce one flat form-table, and the panels here
 * need their own layout. register_setting() is still used, so the option is
 * saved, nonced and sanitised by WordPress exactly as before.
 */
final class SettingsPage
{
    public const SLUG = 'ai-visibility';

    private const NONCE_ACTION = 'ai_visibility_regenerate';

    /**
     * The panels, in navigation order.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function panels(): array
    {
        return [
            'dashboard' => [
                'label' => __('Dashboard', 'ai-visibility'),
                'description' => __('Files and health checks', 'ai-visibility'),
            ],
            'discovery' => [
                'label' => __('Discovery', 'ai-visibility'),
                'description' => __('What gets published', 'ai-visibility'),
            ],
            'content' => [
                'label' => __('Content', 'ai-visibility'),
                'description' => __('What goes into the files', 'ai-visibility'),
            ],
            'crawlers' => [
                'label' => __('AI crawlers', 'ai-visibility'),
                'description' => __('Who may read the site', 'ai-visibility'),
            ],
            'identity' => [
                'label' => __('Identity', 'ai-visibility'),
                'description' => __('Who publishes it', 'ai-visibility'),
            ],
        ];
    }

    /**
     * Hand-drawn 20x20 line icon per panel.
     *
     * Inline and stroked in currentColor so they follow the tab's own state
     * without a second asset, a sprite sheet, or an icon font. Kept
     * deliberately plain: they mark a row, they do not illustrate it.
     *
     * @return non-empty-string
     */
    private static function icon(string $panel): string
    {
        $paths = [
            // A gauge: status at a glance.
            'dashboard' => '<path d="M3 15a9 9 0 1 1 18 0"/><path d="m12 15 4-5"/><circle cx="12" cy="15" r="1.4"/>',
            // Broadcast arcs: the site announcing itself.
            'discovery' => '<circle cx="12" cy="12" r="2"/><path d="M8.5 8.5a5 5 0 0 0 0 7"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M5.8 5.8a9 9 0 0 0 0 12.4"/><path d="M18.2 5.8a9 9 0 0 1 0 12.4"/>',
            // A document with lines of text.
            'content' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6"/><path d="M9 16h6"/>',
            // A crawler: a head with antennae.
            'crawlers' => '<rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 4v4"/><circle cx="12" cy="3" r="1"/><path d="M9 13h.01"/><path d="M15 13h.01"/>',
            // An identity card.
            'identity' => '<rect x="3" y="5" width="18" height="14" rx="3"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.6 1.7-2.4 3-2.4s2.4.8 3 2.4"/><path d="M15 10h3"/><path d="M15 14h3"/>',
        ];

        return '<svg class="aivis__navicon" viewBox="0 0 24 24" width="20" height="20" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true" focusable="false">' . ($paths[$panel] ?? $paths['dashboard']) . '</svg>';
    }

    /**
     * The panel to show on load.
     *
     * Read from the URL so a reload, a bookmark, or the redirect WordPress
     * performs after saving all land back on the panel the user was on.
     * Validated against the panel list, so nothing arbitrary reaches the markup.
     */
    public static function activePanel(): string
    {
        // A nonce would be meaningless here: this selects which panel to draw,
        // it changes nothing. The value is reduced to [a-z0-9_-] and then has to
        // match a panel key, so nothing arbitrary survives to reach the markup.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw = isset($_GET['tab']) ? wp_unslash($_GET['tab']) : '';
        $requested = is_string($raw) ? sanitize_key($raw) : '';

        return array_key_exists($requested, self::panels()) ? $requested : 'dashboard';
    }

    /**
     * URL of the settings screen, optionally pointing at one panel.
     */
    public static function url(string $panel = ''): string
    {
        $url = admin_url('options-general.php?page=' . self::SLUG);

        return $panel === '' ? $url : $url . '&tab=' . rawurlencode($panel);
    }

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
        $settings = Plugin::settings();
        $active = self::activePanel();

        printf('<div class="wrap aivis" data-active-panel="%s">', esc_attr($active));

        echo '<div class="aivis__shell">';
        $this->renderMasthead();

        // WordPress relocates its notices to just after .wp-header-end, or to
        // just after the first heading when that marker is absent — which drops
        // "Settings saved." into the middle of the masthead. This puts them on
        // their own row, between the masthead and the panels.
        echo '<hr class="wp-header-end">';

        echo '<div class="aivis__body">';
        $this->renderNav($active);

        echo '<div class="aivis__panels">';

        // Every panel is rendered, and only hidden visually. A field that is not
        // in the DOM is not submitted, so rendering just the active panel would
        // wipe every setting on the others each time the form is saved.
        echo '<form method="post" action="options.php" class="aivis__form">';
        settings_fields(self::SLUG);

        $this->renderDashboard($active);
        $this->renderDiscovery($settings, $active);
        $this->renderContent($settings, $active);
        $this->renderCrawlers($settings, $active);
        $this->renderIdentity($settings, $active);

        $this->renderSaveBar($active);

        echo '</form></div></div></div></div>';
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
     * Load the screen's assets, on this screen only.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::SLUG) {
            return;
        }

        wp_enqueue_style(
            'ai-visibility-admin',
            plugins_url('assets/admin.css', \Pollora\AiVisibility\PLUGIN_FILE),
            [],
            VERSION,
        );

        wp_enqueue_script(
            'ai-visibility-admin',
            plugins_url('assets/admin.js', \Pollora\AiVisibility\PLUGIN_FILE),
            [],
            VERSION,
            true,
        );

        wp_localize_script('ai-visibility-admin', 'aiVisibilityAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'action' => self::NONCE_ACTION,
            'working' => __('Regenerating…', 'ai-visibility'),
            'failed' => __('Regeneration failed.', 'ai-visibility'),
            'copied' => __('Copied', 'ai-visibility'),
            'copyFailed' => __('Press Ctrl+C to copy', 'ai-visibility'),
        ]);
    }

    // -- Screen furniture --

    private function renderMasthead(): void
    {
        $status = Diagnostics::worst();
        $labels = [
            Diagnostics::PASS => __('Everything checks out', 'ai-visibility'),
            Diagnostics::WARN => __('Needs attention', 'ai-visibility'),
            Diagnostics::FAIL => __('Action required', 'ai-visibility'),
        ];

        printf(
            '<header class="aivis__masthead">'
            . '<div class="aivis__identity"><h1 class="aivis__title">%s</h1>'
            . '<p class="aivis__tagline">%s</p></div>'
            . '<div class="aivis__meta">'
            . '<span class="aivis-pill aivis-pill--%s">%s</span>'
            . '<span class="aivis__version">v%s</span>'
            . '</div></header>',
            esc_html__('AI Visibility', 'ai-visibility'),
            esc_html__('What this site publishes for LLMs, AI search and AI agents.', 'ai-visibility'),
            esc_attr($status),
            esc_html($labels[$status]),
            esc_html(VERSION),
        );
    }

    /**
     * The panel switcher.
     *
     * Real links, not buttons: with JavaScript they switch panels in place,
     * without it they load the same screen at the requested panel. Either way
     * the address bar names the panel, so a reload comes back to it.
     */
    private function renderNav(string $active): void
    {
        echo '<nav class="aivis__nav" aria-label="' . esc_attr__('Settings sections', 'ai-visibility') . '">';
        echo '<ul class="aivis__navlist" role="tablist" aria-orientation="vertical">';

        foreach (self::panels() as $id => $panel) {
            $selected = $id === $active;

            printf(
                '<li class="aivis__navrow" role="presentation">'
                . '<a role="tab" class="aivis__navitem" href="%1$s" id="aivis-tab-%2$s" '
                . 'aria-controls="aivis-panel-%2$s" aria-selected="%3$s" tabindex="%4$s" data-panel="%2$s">'
                . '%5$s'
                . '<span class="aivis__navtext"><span class="aivis__navlabel">%6$s</span>'
                . '<span class="aivis__navdesc">%7$s</span></span></a></li>',
                esc_url(self::url($id)),
                esc_attr($id),
                $selected ? 'true' : 'false',
                $selected ? '0' : '-1',
                // A hardcoded SVG literal from self::icon(): no input reaches it,
                // and escaping it would print the markup instead of drawing it.
                self::icon($id), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                esc_html($panel['label']),
                esc_html($panel['description']),
            );
        }

        echo '</ul></nav>';
    }

    private function renderSaveBar(string $active): void
    {
        printf(
            '<div class="aivis__savebar"%s>'
            . '<p class="aivis__savenote">%s</p>'
            . '<button type="submit" class="aivis-button aivis-button--primary">%s</button>'
            . '</div>',
            $active === 'dashboard' ? ' hidden' : '',
            esc_html__('Files are rebuilt automatically when a tracked post is saved.', 'ai-visibility'),
            esc_html__('Save changes', 'ai-visibility'),
        );
    }

    private function openPanel(string $id, string $active, string $title, string $intro): void
    {
        printf(
            '<section class="aivis__panel" id="aivis-panel-%1$s" role="tabpanel" aria-labelledby="aivis-tab-%1$s" tabindex="0"%2$s>'
            . '<div class="aivis__panelhead"><h2 class="aivis__panetitle">%3$s</h2><p class="aivis__paneintro">%4$s</p></div>',
            esc_attr($id),
            $id === $active ? '' : ' hidden',
            esc_html($title),
            esc_html($intro),
        );
    }

    // -- Panels --

    private function renderDashboard(string $active): void
    {
        $this->openPanel(
            'dashboard',
            $active,
            __('Dashboard', 'ai-visibility'),
            __('The files this site publishes, and whether anything is stopping them from being served.', 'ai-visibility'),
        );

        echo '<div class="aivis-grid">';

        foreach (Artifact::cases() as $artifact) {
            $this->renderArtifactCard($artifact);
        }

        echo '</div>';

        $this->renderChecks();

        printf(
            '<div class="aivis-actions">'
            . '<button type="button" class="aivis-button aivis-button--primary" id="ai-visibility-regenerate">%s</button>'
            . '<span class="aivis-actions__status" id="ai-visibility-status" role="status" aria-live="polite"></span>'
            . '</div>',
            esc_html__('Regenerate all files', 'ai-visibility'),
        );

        echo '</section>';
    }

    private function renderArtifactCard(Artifact $artifact): void
    {
        $stat = FileStore::stat($artifact);

        printf(
            '<article class="aivis-file%1$s">'
            . '<header class="aivis-file__head">'
            . '<h3 class="aivis-file__name">%2$s</h3>'
            . '<span class="aivis-dot aivis-dot--%3$s" aria-hidden="true"></span>'
            . '</header>'
            . '<dl class="aivis-file__stats">'
            . '<div><dt>%4$s</dt><dd>%5$s</dd></div>'
            . '<div><dt>%6$s</dt><dd>%7$s</dd></div>'
            . '</dl>'
            . '<footer class="aivis-file__foot">'
            . '<a class="aivis-link" href="%8$s" target="_blank" rel="noopener">%9$s</a>'
            . '<button type="button" class="aivis-button aivis-button--quiet aivis-copy" data-copy="%8$s">%10$s</button>'
            . '</footer></article>',
            $stat === null ? ' is-missing' : '',
            esc_html($artifact->value),
            esc_attr($stat === null ? 'warn' : 'pass'),
            esc_html__('Size', 'ai-visibility'),
            esc_html($stat === null ? '—' : self::formatSize($stat['size'])),
            esc_html__('Updated', 'ai-visibility'),
            esc_html($stat === null ? __('Not generated', 'ai-visibility') : self::formatTime($stat['mtime'])),
            esc_url($artifact->url()),
            esc_html__('View', 'ai-visibility'),
            esc_html__('Copy URL', 'ai-visibility'),
        );
    }

    private function renderChecks(): void
    {
        printf('<h3 class="aivis-subhead">%s</h3>', esc_html__('Checks', 'ai-visibility'));
        echo '<ul class="aivis-checks">';

        foreach (Diagnostics::all() as $check) {
            printf(
                '<li class="aivis-check aivis-check--%1$s">'
                . '<span class="aivis-dot aivis-dot--%1$s" aria-hidden="true"></span>'
                . '<span class="aivis-check__body"><span class="aivis-check__label">%2$s</span>'
                . '<span class="aivis-check__detail">%3$s</span></span></li>',
                esc_attr($check['status']),
                esc_html($check['label']),
                esc_html($check['detail']),
            );
        }

        echo '</ul>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function renderDiscovery(array $settings, string $active): void
    {
        $this->openPanel(
            'discovery',
            $active,
            __('Discovery', 'ai-visibility'),
            __('Which formats this site publishes. Turning one off removes its endpoint entirely.', 'ai-visibility'),
        );

        echo '<div class="aivis-stack">';

        Field::toggle(
            'enable_llms_txt',
            __('llms.txt and llms-full.txt', 'ai-visibility'),
            __('A structured index of the site, and one document containing every tracked page.', 'ai-visibility'),
            (bool) $settings['enable_llms_txt'],
        );
        Field::toggle(
            'enable_markdown',
            __('Markdown for every page', 'ai-visibility'),
            __('Serves /any-page.md and ?format=md, and advertises it from the HTML page.', 'ai-visibility'),
            (bool) $settings['enable_markdown'],
        );
        Field::toggle(
            'enable_robots',
            __('AI directives in robots.txt', 'ai-visibility'),
            __('Adds an explicit Allow or Disallow block per AI crawler.', 'ai-visibility'),
            (bool) $settings['enable_robots'],
        );
        Field::toggle(
            'enable_discovery',
            __('Discovery files', 'ai-visibility'),
            __('Publishes ai.txt and identity.json under /.well-known/ and /ai-discovery/.', 'ai-visibility'),
            (bool) $settings['enable_discovery'],
        );
        Field::toggle(
            'enable_abilities',
            __('MCP abilities', 'ai-visibility'),
            __('Lets an MCP client query this site directly. Requires WordPress 6.9 or newer.', 'ai-visibility'),
            (bool) $settings['enable_abilities'],
        );

        echo '</div></section>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function renderContent(array $settings, string $active): void
    {
        $this->openPanel(
            'content',
            $active,
            __('Content', 'ai-visibility'),
            __('What goes into the generated files, and how much of it.', 'ai-visibility'),
        );

        Field::textarea(
            'site_description',
            __('How to describe this site', 'ai-visibility'),
            __('What the site is, who it serves, what it covers. Appears at the top of llms.txt, ai.txt and identity.json. Left empty, the WordPress tagline is used.', 'ai-visibility'),
            self::asString($settings['site_description']),
            4,
            __('A municipal opposition site covering local council decisions, budgets and public consultations.', 'ai-visibility'),
        );

        printf(
            '<div class="aivis-field"><span class="aivis-field__label">%s</span>'
            . '<p class="aivis-field__help">%s</p><div class="aivis-typegrid">',
            esc_html__('Post types to publish', 'ai-visibility'),
            esc_html__('Only public post types are listed. Attachments are never included.', 'ai-visibility'),
        );

        $selected = is_array($settings['post_types']) ? $settings['post_types'] : [];

        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }

            Field::typeCard(
                'post_types',
                $type->name,
                self::typeLabel($type),
                self::publishedCount($type->name),
                in_array($type->name, $selected, true),
            );
        }

        echo '</div></div>';

        Field::number(
            'posts_per_type',
            __('Entries per post type', 'ai-visibility'),
            __('An upper bound per type, so llms.txt stays readable on a large site.', 'ai-visibility'),
            is_numeric($settings['posts_per_type']) ? (int) $settings['posts_per_type'] : Plugin::defaultSettings()['posts_per_type'],
            Plugin::MIN_POSTS_PER_TYPE,
            Plugin::MAX_POSTS_PER_TYPE,
        );

        echo '</section>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function renderCrawlers(array $settings, string $active): void
    {
        $this->openPanel(
            'crawlers',
            $active,
            __('AI crawlers', 'ai-visibility'),
            __('One crawler name per line. These become User-agent blocks in robots.txt.', 'ai-visibility'),
        );

        echo '<div class="aivis-columns">';

        Field::textarea(
            'crawlers_allow',
            __('Allowed', 'ai-visibility'),
            __('Crawlers that answer questions and cite sources. Allowing them is how the site gets quoted.', 'ai-visibility'),
            implode("\n", self::asStringList($settings['crawlers_allow'])),
            8,
            "GPTBot\nClaudeBot\nPerplexityBot",
        );

        Field::textarea(
            'crawlers_block',
            __('Blocked', 'ai-visibility'),
            __('Crawlers that collect training data without attribution.', 'ai-visibility'),
            implode("\n", self::asStringList($settings['crawlers_block'])),
            8,
            "CCBot\nBytespider",
        );

        echo '</div>';

        printf(
            '<p class="aivis-note">%s</p>',
            esc_html__('A crawler named in neither list is not mentioned in robots.txt at all, and follows whatever your general rules say.', 'ai-visibility'),
        );

        echo '</section>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function renderIdentity(array $settings, string $active): void
    {
        $this->openPanel(
            'identity',
            $active,
            __('Identity', 'ai-visibility'),
            __('Who is behind the site. Published in identity.json as a schema.org Organization.', 'ai-visibility'),
        );

        $email = self::asString($settings['identity_email']);

        if ($email === '') {
            $adminEmail = get_option('admin_email');
            $email = is_string($adminEmail) ? $adminEmail : '';
        }

        Field::input(
            'identity_email',
            __('Contact email', 'ai-visibility'),
            __('Where an AI agent or its operator should write. Defaults to the site administrator.', 'ai-visibility'),
            $email,
            'email',
        );

        Field::textarea(
            'identity_socials',
            __('Profiles elsewhere', 'ai-visibility'),
            __('One URL per line. Published as sameAs, which is how an engine confirms two profiles are the same organisation.', 'ai-visibility'),
            implode("\n", self::asStringList($settings['identity_socials'])),
            4,
            "https://example.com/@handle\nhttps://www.linkedin.com/company/name",
        );

        echo '</section>';
    }

    // -- Internals --

    /**
     * A byte count as a human-readable size.
     */
    private static function formatSize(int $bytes): string
    {
        $formatted = size_format($bytes);

        return is_string($formatted) ? $formatted : $bytes . ' B';
    }

    /**
     * A timestamp in the site's own date and time format.
     */
    private static function formatTime(int $timestamp): string
    {
        $date = get_option('date_format');
        $time = get_option('time_format');

        $format = (is_string($date) ? $date : 'Y-m-d') . ' ' . (is_string($time) ? $time : 'H:i');
        $formatted = wp_date($format, $timestamp);

        return is_string($formatted) ? $formatted : gmdate('Y-m-d H:i', $timestamp);
    }

    /**
     * Published entries for a post type, as a short human string.
     */
    private static function publishedCount(string $postType): string
    {
        $counts = wp_count_posts($postType);
        $published = is_object($counts) ? ($counts->publish ?? 0) : 0;
        $total = is_numeric($published) ? (int) $published : 0;

        return sprintf(
            /* translators: %s: number of published entries. */
            _n('%s published', '%s published', $total, 'ai-visibility'),
            number_format_i18n($total),
        );
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

    /**
     * @return list<string>
     */
    private static function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
