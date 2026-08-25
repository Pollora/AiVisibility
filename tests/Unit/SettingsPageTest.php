<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Admin\SettingsPage;
use Pollora\AiVisibility\Plugin;

beforeEach(function (): void {
    $this->page = new SettingsPage();

    Functions\when('post_type_exists')->alias(
        static fn (string $type): bool => in_array($type, ['post', 'page', 'event'], true),
    );
});

describe('sanitize', function (): void {
    it('produces the canonical settings shape whatever the form posted', function (): void {
        $sanitized = $this->page->sanitize([]);

        expect(array_keys($sanitized))->toBe(array_keys(Plugin::defaultSettings()));
    });

    it('falls back to the defaults when the input is not an array', function (mixed $input): void {
        expect($this->page->sanitize($input))->toBe(Plugin::defaultSettings());
    })->with(['a string', 42, null, true]);

    it('treats an absent checkbox as off, as HTML forms do', function (): void {
        $sanitized = $this->page->sanitize(['enable_markdown' => '1']);

        expect($sanitized['enable_markdown'])->toBeTrue()
            ->and($sanitized['enable_robots'])->toBeFalse()
            ->and($sanitized['enable_llms_txt'])->toBeFalse();
    });

    it('rejects post types that are not registered', function (): void {
        $sanitized = $this->page->sanitize(['post_types' => ['post', 'not_a_type', 'event']]);

        expect($sanitized['post_types'])->toBe(['post', 'event']);
    });

    it('ignores non-string entries in the post type list', function (): void {
        $sanitized = $this->page->sanitize(['post_types' => ['post', 42, ['nested'], null]]);

        expect($sanitized['post_types'])->toBe(['post']);
    });

    it('clamps posts_per_type on the way in as well as on the way out', function (mixed $input, int $expected): void {
        expect($this->page->sanitize(['posts_per_type' => $input])['posts_per_type'])->toBe($expected);
    })->with([
        'zero' => [0, 1],
        'negative' => ['-10', 1],
        'huge' => [99_999, 200],
        'valid' => ['30', 30],
        'nonsense' => ['thirty', 50],
    ]);

    it('splits the crawler textarea into one entry per line', function (): void {
        $sanitized = $this->page->sanitize(['crawlers_allow' => "GPTBot\nClaudeBot\n\n  PerplexityBot  \n"]);

        expect($sanitized['crawlers_allow'])->toBe(['GPTBot', 'ClaudeBot', 'PerplexityBot']);
    });

    it('accepts an already-split crawler array', function (): void {
        $sanitized = $this->page->sanitize(['crawlers_block' => ['CCBot', '', 'Bytespider']]);

        expect($sanitized['crawlers_block'])->toBe(['CCBot', 'Bytespider']);
    });

    it('strips markup from crawler names', function (): void {
        $sanitized = $this->page->sanitize(['crawlers_allow' => '<script>alert(1)</script>GPTBot']);

        expect($sanitized['crawlers_allow'])->toBe(['alert(1)GPTBot']);
    });

    it('drops an invalid email rather than storing it', function (): void {
        expect($this->page->sanitize(['identity_email' => 'not-an-email'])['identity_email'])->toBe('')
            ->and($this->page->sanitize(['identity_email' => 'hi@example.test'])['identity_email'])
            ->toBe('hi@example.test');
    });

    it('splits and cleans the social links textarea', function (): void {
        $sanitized = $this->page->sanitize([
            'identity_socials' => "https://example.test/a\n\n  https://example.test/b  \n",
        ]);

        expect($sanitized['identity_socials'])->toBe(['https://example.test/a', 'https://example.test/b']);
    });

    it('strips markup from the site description', function (): void {
        $sanitized = $this->page->sanitize(['site_description' => '<b>Bold</b> claim']);

        expect($sanitized['site_description'])->toBe('Bold claim');
    });

    it('round-trips: sanitised output survives normalisation unchanged', function (): void {
        $sanitized = $this->page->sanitize([
            'enable_llms_txt' => '1',
            'post_types' => ['post', 'page'],
            'posts_per_type' => '25',
            'crawlers_allow' => "GPTBot\nClaudeBot",
            'identity_email' => 'hi@example.test',
        ]);

        expect(Plugin::normalize($sanitized))->toBe($sanitized);
    });
});

describe('handleRegenerate', function (): void {
    it('refuses an unprivileged user before checking the nonce', function (): void {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('check_ajax_referer')->never();
        Functions\expect('wp_send_json_error')->once()->andThrow(new RuntimeException('halted'));

        expect(fn () => $this->page->handleRegenerate())->toThrow(RuntimeException::class);
    });
});

describe('enqueueAssets', function (): void {
    it('loads nothing outside its own settings screen', function (string $hook): void {
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script')->never();

        $this->page->enqueueAssets($hook);
    })->with(['index.php', 'post.php', 'options-general.php', 'settings_page_other-plugin']);

    it('loads the script on its own screen, with a nonce', function (): void {
        Functions\when('plugins_url')->justReturn('https://example.test/plugin/assets/admin.js');
        Functions\when('admin_url')->justReturn('https://example.test/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('a-nonce');
        Functions\expect('wp_enqueue_style')->once();
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_localize_script')
            ->once()
            ->withArgs(static function (string $handle, string $object, array $data): bool {
                return $handle === 'ai-visibility-admin'
                    && $object === 'aiVisibilityAdmin'
                    && $data['nonce'] === 'a-nonce'
                    && isset($data['ajaxUrl'], $data['action']);
            });

        $this->page->enqueueAssets('settings_page_ai-visibility');
    });
});

describe('panels', function (): void {
    it('lists the panels in navigation order', function (): void {
        expect(array_keys(SettingsPage::panels()))
            ->toBe(['dashboard', 'discovery', 'content', 'crawlers', 'identity']);
    });

    it('opens on the dashboard when no panel is requested', function (): void {
        expect(SettingsPage::activePanel())->toBe('dashboard');
    });

    it('honours a requested panel, so a reload comes back to it', function (string $panel): void {
        $_GET['tab'] = $panel;

        expect(SettingsPage::activePanel())->toBe($panel);
    })->with(['discovery', 'content', 'crawlers', 'identity']);

    it('refuses anything that is not a panel', function (mixed $requested): void {
        $_GET['tab'] = $requested;

        expect(SettingsPage::activePanel())->toBe('dashboard');
    })->with([
        'unknown name' => ['nope'],
        'markup' => ['"><script>alert(1)</script>'],
        'path traversal' => ['../../wp-config.php'],
        'an array' => [['discovery']],
        'empty' => [''],
    ]);

    it('builds a URL per panel', function (): void {
        Functions\when('admin_url')->alias(
            static fn (string $path): string => 'https://example.test/wp-admin/' . $path,
        );

        expect(SettingsPage::url())->toBe('https://example.test/wp-admin/options-general.php?page=ai-visibility')
            ->and(SettingsPage::url('crawlers'))->toContain('&tab=crawlers');
    });

    afterEach(function (): void {
        unset($_GET['tab']);
    });
});

describe('render', function (): void {
    beforeEach(function (): void {
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://example.test/wp-admin/options-general.php');
        Functions\when('get_post_types')->justReturn([
            'post' => new WP_Post_Type('post', 'Post', 'Posts'),
            'attachment' => new WP_Post_Type('attachment', 'Media', 'Media'),
        ]);
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 4]);
        Functions\when('number_format_i18n')->alias(static fn ($n) => (string) $n);
        Functions\when('_n')->alias(static fn ($single, $plural, $count) => $count === 1 ? $single : $plural);
        Functions\when('wp_upload_dir')->justReturn(['error' => false, 'basedir' => sys_get_temp_dir()]);
        Functions\when('wp_mkdir_p')->justReturn(true);
        Functions\when('size_format')->alias(static fn ($b) => $b . ' B');

        $this->markup = function (): string {
            ob_start();
            $this->page->render();

            return (string) ob_get_clean();
        };
    });

    it('renders every panel, so saving one never wipes the others', function (): void {
        // A field that is not in the DOM is not submitted, and sanitize()
        // rebuilds the whole option from what was posted. Rendering only the
        // active panel would silently reset every setting on the other four.
        $markup = ($this->markup)();

        foreach (array_keys(SettingsPage::panels()) as $panel) {
            expect($markup)->toContain('id="aivis-panel-' . $panel . '"');
        }
    });

    it('carries an input for every stored setting', function (): void {
        $markup = ($this->markup)();

        foreach (array_keys(Plugin::defaultSettings()) as $key) {
            expect($markup)->toContain('ai_visibility_settings[' . $key . ']');
        }
    });

    it('shows only the requested panel', function (): void {
        $_GET['tab'] = 'crawlers';

        $markup = ($this->markup)();

        expect($markup)->toContain('id="aivis-panel-crawlers" role="tabpanel" aria-labelledby="aivis-tab-crawlers" tabindex="0">')
            ->and($markup)->toContain('id="aivis-panel-content" role="tabpanel" aria-labelledby="aivis-tab-content" tabindex="0" hidden>');
    });

    it('marks the requested tab as selected and the rest as not', function (): void {
        $_GET['tab'] = 'identity';

        $markup = ($this->markup)();

        expect(substr_count($markup, 'aria-selected="true"'))->toBe(1)
            ->and($markup)->toContain('id="aivis-tab-identity" aria-controls="aivis-panel-identity" aria-selected="true"');
    });

    it('navigates with links, so the panels work without JavaScript', function (): void {
        $markup = ($this->markup)();

        foreach (array_keys(SettingsPage::panels()) as $panel) {
            expect($markup)->toContain('tab=' . $panel);
        }

        expect($markup)->toContain('<a role="tab"');
    });

    it('hides the save bar on the dashboard and shows it elsewhere', function (): void {
        expect(($this->markup)())->toContain('<div class="aivis__savebar" hidden>');

        $_GET['tab'] = 'discovery';

        expect(($this->markup)())->toContain('<div class="aivis__savebar">');
    });

    it('posts to options.php so WordPress handles the nonce and the option', function (): void {
        expect(($this->markup)())->toContain('<form method="post" action="options.php"');
    });

    it('never offers attachments as a publishable post type', function (): void {
        expect(($this->markup)())->toContain('value="post"')->not->toContain('value="attachment"');
    });

    afterEach(function (): void {
        unset($_GET['tab']);
    });
});

describe('navigation', function (): void {
    beforeEach(function (): void {
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://example.test/wp-admin/options-general.php');
        Functions\when('get_post_types')->justReturn([]);
        Functions\when('wp_upload_dir')->justReturn(['error' => false, 'basedir' => sys_get_temp_dir()]);
        Functions\when('wp_mkdir_p')->justReturn(true);
        Functions\when('size_format')->alias(static fn ($b) => $b . ' B');

        ob_start();
        $this->page->render();
        $this->markup = (string) ob_get_clean();
    });

    it('gives every panel a label and a description', function (): void {
        foreach (SettingsPage::panels() as $panel) {
            expect($panel['label'])->not->toBe('')
                ->and($panel['description'])->not->toBe('');
        }
    });

    it('shows both the label and the description in the sidebar', function (): void {
        foreach (SettingsPage::panels() as $panel) {
            expect($this->markup)->toContain('>' . $panel['label'] . '<')
                ->and($this->markup)->toContain('>' . $panel['description'] . '<');
        }
    });

    it('draws one icon per panel', function (): void {
        expect(substr_count($this->markup, 'class="aivis__navicon"'))
            ->toBe(count(SettingsPage::panels()));
    });

    it('hides the icons from assistive technology, since the label says it', function (): void {
        preg_match_all('/<svg class="aivis__navicon"[^>]*>/', $this->markup, $icons);

        foreach ($icons[0] as $icon) {
            expect($icon)->toContain('aria-hidden="true"')->toContain('focusable="false"');
        }
    });

    it('ships the icons inline rather than fetching them', function (): void {
        // An icon font or a remote sprite would be an outbound request from
        // wp-admin, which this plugin does not make anywhere.
        expect($this->markup)->toContain('<svg')
            ->not->toContain('<img')
            ->not->toContain('http://fonts.')
            ->not->toContain('https://fonts.');
    });

    it('tells WordPress where to put its notices', function (): void {
        // Without this marker WordPress injects "Settings saved." after the
        // first heading, which lands in the middle of the masthead.
        expect($this->markup)->toContain('<hr class="wp-header-end">');
    });

    it('wraps the screen in a single surface', function (): void {
        expect($this->markup)->toContain('<div class="aivis__shell">');
    });
});
