<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Admin\SettingsPage;

use const Pollora\AiVisibility\VERSION;

beforeEach(function (): void {
    $this->page = new SettingsPage();

    Functions\when('post_type_exists')->alias(
        static fn (string $type): bool => in_array($type, ['post', 'page', 'event'], true),
    );
});

describe('sanitize', function (): void {
    it('turns every switch off and empties every list when nothing was posted', function (): void {
        // HTML forms omit unchecked boxes and empty fields, so an empty post is
        // the "everything off" state, not "keep the defaults".
        expect($this->page->sanitize([]))->toBe([
            'enable_llms_txt' => false,
            'enable_markdown' => false,
            'enable_robots' => false,
            'enable_discovery' => false,
            'enable_abilities' => false,
            'site_description' => '',
            'post_types' => [],
            'posts_per_type' => 50,
            'crawlers_allow' => [],
            'crawlers_block' => [],
            'identity_email' => '',
            'identity_socials' => [],
        ]);
    });

    it('keeps every field of a complete post', function (): void {
        $sanitized = $this->page->sanitize([
            'enable_llms_txt' => '1',
            'enable_markdown' => '1',
            'enable_robots' => '1',
            'enable_discovery' => '1',
            'enable_abilities' => '1',
            'post_types' => ['page', 'event'],
            'site_description' => 'A site',
            'posts_per_type' => '25',
            'crawlers_allow' => "GPTBot\nClaudeBot",
            'crawlers_block' => "CCBot\nBytespider",
            'identity_email' => 'hi@example.test',
            'identity_socials' => "https://a.test/x\nhttps://b.test/y",
        ]);

        expect($sanitized)->toBe([
            'enable_llms_txt' => true,
            'enable_markdown' => true,
            'enable_robots' => true,
            'enable_discovery' => true,
            'enable_abilities' => true,
            'site_description' => 'A site',
            'post_types' => ['page', 'event'],
            'posts_per_type' => 25,
            'crawlers_allow' => ['GPTBot', 'ClaudeBot'],
            'crawlers_block' => ['CCBot', 'Bytespider'],
            'identity_email' => 'hi@example.test',
            'identity_socials' => ['https://a.test/x', 'https://b.test/y'],
        ]);
    });

    it('turns each switch on by itself and leaves the others off', function (string $key): void {
        $sanitized = $this->page->sanitize([$key => '1']);

        foreach (['enable_llms_txt', 'enable_markdown', 'enable_robots', 'enable_discovery', 'enable_abilities'] as $switch) {
            expect($sanitized[$switch])->toBe($switch === $key);
        }
    })->with(['enable_llms_txt', 'enable_markdown', 'enable_robots', 'enable_discovery', 'enable_abilities']);

    it('reads a scalar that is not a string as its text', function (): void {
        expect($this->page->sanitize(['site_description' => 12345])['site_description'])->toBe('12345')
            ->and($this->page->sanitize(['site_description' => true])['site_description'])->toBe('1');
    });

    it('reads a non-scalar as empty text', function (): void {
        $sanitized = $this->page->sanitize(['site_description' => ['x'], 'identity_email' => ['a@b.test']]);

        expect($sanitized['site_description'])->toBe('')
            ->and($sanitized['identity_email'])->toBe('');
    });

    it('ignores array entries that are not strings or numbers in the crawler list', function (): void {
        $sanitized = $this->page->sanitize(['crawlers_allow' => ['GPTBot', ['x'], null, 7]]);

        expect($sanitized['crawlers_allow'])->toBe(['GPTBot', '7']);
    });

    it('ignores social links posted as an array', function (): void {
        expect($this->page->sanitize(['identity_socials' => ['https://a.test']])['identity_socials'])->toBe([]);
    });

    it('trims each crawler name and each link before WordPress sees it', function (): void {
        // Real esc_url_raw() turns a trailing space into %20 and real
        // sanitize_text_field() is not guaranteed to trim a lone tab, so the
        // doubles here do not trim and the trimming is the plugin's own.
        Functions\when('esc_url_raw')->alias(static fn ($u) => (string) $u);
        Functions\when('sanitize_text_field')->alias(static fn ($t) => strip_tags((string) $t));

        $sanitized = $this->page->sanitize([
            'crawlers_allow' => ["  GPTBot\t", ['  ClaudeBot  ']],
            'crawlers_block' => ['  CCBot  '],
            'identity_socials' => "  https://a.test/x \n\t\n https://b.test/y\t",
        ]);

        expect($sanitized['crawlers_allow'])->toBe(['GPTBot'])
            ->and($sanitized['crawlers_block'])->toBe(['CCBot'])
            ->and($sanitized['identity_socials'])->toBe(['https://a.test/x', 'https://b.test/y']);
    });

    it('trims a crawler textarea line by line', function (): void {
        Functions\when('sanitize_text_field')->alias(static fn ($t) => strip_tags((string) $t));
        Functions\when('sanitize_textarea_field')->alias(static fn ($t) => strip_tags((string) $t));

        expect($this->page->sanitize(['crawlers_allow' => "  GPTBot \n\t\n ClaudeBot\t"])['crawlers_allow'])
            ->toBe(['GPTBot', 'ClaudeBot']);
    });
});

describe('handleRegenerate', function (): void {
    it('answers 403 with a message to a user who may not regenerate', function (): void {
        Functions\when('current_user_can')->justReturn(false);
        $sent = null;
        Functions\when('wp_send_json_error')->alias(static function (...$args) use (&$sent): void {
            $sent = $args;

            throw new RuntimeException('halted');
        });

        expect(fn () => $this->page->handleRegenerate())->toThrow(RuntimeException::class, 'halted')
            ->and($sent)->toBe([['message' => 'You are not allowed to regenerate these files.'], 403]);
    });

    it('checks the capability named manage_options', function (): void {
        $asked = null;
        Functions\when('current_user_can')->alias(static function (string $cap) use (&$asked): bool {
            $asked = $cap;

            return false;
        });
        Functions\when('wp_send_json_error')->alias(static function (): void {
            throw new RuntimeException('halted');
        });

        expect(fn () => $this->page->handleRegenerate())->toThrow(RuntimeException::class)
            ->and($asked)->toBe('manage_options');
    });
});

describe('enqueueAssets', function (): void {
    it('registers the stylesheet and the footer script, with their data', function (): void {
        Functions\when('plugins_url')->alias(static fn (string $path, string $file): string => 'https://example.test/p/' . $path);
        Functions\when('admin_url')->alias(static fn (string $path): string => 'https://example.test/wp-admin/' . $path);
        Functions\when('wp_create_nonce')->alias(static fn (string $action): string => 'nonce-for-' . $action);

        $calls = [];
        Functions\when('wp_enqueue_style')->alias(static function (...$args) use (&$calls): void {
            $calls['style'] = $args;
        });
        Functions\when('wp_enqueue_script')->alias(static function (...$args) use (&$calls): void {
            $calls['script'] = $args;
        });
        Functions\when('wp_localize_script')->alias(static function (...$args) use (&$calls): void {
            $calls['localize'] = $args;
        });

        $this->page->enqueueAssets('settings_page_ai-visibility');

        expect($calls['style'])->toBe(['ai-visibility-admin', 'https://example.test/p/assets/admin.css', [], VERSION])
            ->and($calls['script'])->toBe(['ai-visibility-admin', 'https://example.test/p/assets/admin.js', [], VERSION, true])
            ->and($calls['localize'])->toBe([
                'ai-visibility-admin',
                'aiVisibilityAdmin',
                [
                    'ajaxUrl' => 'https://example.test/wp-admin/admin-ajax.php',
                    'nonce' => 'nonce-for-ai_visibility_regenerate',
                    'action' => 'ai_visibility_regenerate',
                    'working' => 'Regenerating…',
                    'failed' => 'Regeneration failed.',
                    'copied' => 'Copied',
                    'copyFailed' => 'Press Ctrl+C to copy',
                ],
            ]);
    });
});

describe('render', function (): void {
    it('emits the Settings API hidden fields for its own group, inside the form', function (): void {
        $group = null;
        Functions\when('settings_fields')->alias(static function (string $g) use (&$group): void {
            $group = $g;
            echo '<input type="hidden" name="option_page">';
        });
        Functions\when('admin_url')->justReturn('https://example.test/wp-admin/options-general.php');
        Functions\when('get_post_types')->justReturn([]);
        Functions\when('wp_upload_dir')->justReturn(['error' => false, 'basedir' => sys_get_temp_dir()]);
        Functions\when('wp_mkdir_p')->justReturn(true);

        ob_start();
        $this->page->render();
        $markup = (string) ob_get_clean();

        expect($group)->toBe('ai-visibility')
            ->and($markup)->toContain('<form method="post" action="options.php" class="aivis__form"><input type="hidden" name="option_page">');
    });
});
