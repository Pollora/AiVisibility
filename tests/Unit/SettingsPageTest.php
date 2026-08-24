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
        Functions\expect('wp_enqueue_script')->never();

        $this->page->enqueueAssets($hook);
    })->with(['index.php', 'post.php', 'options-general.php', 'settings_page_other-plugin']);

    it('loads the script on its own screen, with a nonce', function (): void {
        Functions\when('plugins_url')->justReturn('https://example.test/plugin/assets/admin.js');
        Functions\when('admin_url')->justReturn('https://example.test/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('a-nonce');
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
