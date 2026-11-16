<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Admin\Diagnostics;
use Pollora\AiVisibility\Admin\SettingsPage;

use const Pollora\AiVisibility\VERSION;

beforeEach(function (): void {
    $this->page = new SettingsPage();
    $this->uploads = sys_get_temp_dir() . '/ai-visibility-page-' . bin2hex(random_bytes(6));
    mkdir($this->uploads . '/ai-visibility', 0o777, true);

    // Three of the four files exist, with known sizes and a known mtime.
    file_put_contents($this->uploads . '/ai-visibility/llms.txt', str_repeat('x', 12));
    file_put_contents($this->uploads . '/ai-visibility/ai.txt', str_repeat('x', 345));
    file_put_contents($this->uploads . '/ai-visibility/identity.json', '{}');
    foreach (['llms.txt', 'ai.txt', 'identity.json'] as $file) {
        touch($this->uploads . '/ai-visibility/' . $file, 86400 * 366 + 3600 * 5 + 60 * 7);
    }

    $this->setOption('permalink_structure', '/%postname%/');
    $this->setOption('blog_public', '1');
    $this->setOption('date_format', 'Y-m-d');
    $this->setOption('time_format', 'H:i');
    $this->setOption('admin_email', 'admin@example.test');
    $this->setSettings([
        'enable_llms_txt' => true,
        'enable_markdown' => false,
        'enable_robots' => true,
        'enable_discovery' => false,
        'enable_abilities' => true,
        'site_description' => 'About <us>',
        'post_types' => ['post'],
        'posts_per_type' => 30,
        'crawlers_allow' => ['GPTBot', 'ClaudeBot'],
        'crawlers_block' => ['CCBot'],
        'identity_email' => '',
        'identity_socials' => ['https://a.test/x', 'https://b.test/y'],
    ]);

    Functions\when('settings_fields')->justReturn(null);
    Functions\when('wp_upload_dir')->alias(fn (): array => ['basedir' => $this->uploads, 'error' => false]);
    Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0o777, true));
    Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://example.test/wp-admin/' . $p);
    Functions\when('get_post_types')->justReturn([
        'post' => new WP_Post_Type('post', 'Post', 'Posts'),
        'attachment' => new WP_Post_Type('attachment', 'Media', 'Media'),
        'event' => new WP_Post_Type('event', 'Event', 'Events <b>'),
    ]);
    Functions\when('wp_count_posts')->justReturn((object) ['publish' => 4]);
    Functions\when('number_format_i18n')->alias(static fn ($n) => (string) $n);
    // Typed like the real thing, so a count that was not cast to int is an error.
    Functions\when('_n')->alias(static fn (string $single, string $plural, int $count) => $count === 1 ? $single : $plural);
    Functions\when('wp_register_ability')->justReturn(null);

    $this->markup = function (string $tab = ''): string {
        if ($tab !== '') {
            $_GET['tab'] = $tab;
        }

        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    };
});

afterEach(function (): void {
    unset($_GET['tab']);
    exec('rm -rf ' . escapeshellarg($this->uploads));
});

it('renders the whole screen exactly, with one panel open', function (): void {
    $expected = str_replace('{{VERSION}}', VERSION, (string) file_get_contents(__DIR__ . '/../Fixtures/settings-page-discovery.html'));

    expect(($this->markup)('discovery'))->toBe($expected);
});

it('opens the dashboard by default, with the save bar hidden', function (): void {
    $markup = ($this->markup)();

    expect($markup)->toContain('<div class="wrap aivis" data-active-panel="dashboard">')
        ->toContain('id="aivis-panel-dashboard" role="tabpanel" aria-labelledby="aivis-tab-dashboard" tabindex="0"><div')
        ->toContain('id="aivis-panel-discovery" role="tabpanel" aria-labelledby="aivis-tab-discovery" tabindex="0" hidden>')
        ->toContain('<div class="aivis__savebar" hidden>')
        ->toContain('id="aivis-tab-dashboard" aria-controls="aivis-panel-dashboard" aria-selected="true" tabindex="0"');
});

describe('masthead status', function (): void {
    it('says everything checks out on a healthy site', function (): void {
        foreach (['llms.txt', 'llms-full.txt', 'ai.txt', 'identity.json'] as $file) {
            file_put_contents($this->uploads . '/ai-visibility/' . $file, 'x');
        }

        expect(Diagnostics::worst())->toBe(Diagnostics::PASS)
            ->and(($this->markup)())->toContain('<span class="aivis-pill aivis-pill--pass">Everything checks out</span>');
    });

    it('asks for attention when something is merely degraded', function (): void {
        expect(($this->markup)())->toContain('<span class="aivis-pill aivis-pill--warn">Needs attention</span>');
    });

    it('asks for action when something is broken', function (): void {
        $this->setOption('permalink_structure', '');

        expect(($this->markup)())->toContain('<span class="aivis-pill aivis-pill--fail">Action required</span>')
            ->toContain('<li class="aivis-check aivis-check--fail">')
            ->toContain('Set to anything but &quot;Plain&quot; under Settings → Permalinks, or every endpoint answers 404.');
    });

    it('prints the plugin version', function (): void {
        expect(($this->markup)())->toContain('<span class="aivis__version">v' . VERSION . '</span>');
    });
});

describe('the checks list', function (): void {
    it('prints one row per diagnostic, in order', function (): void {
        $this->setOption('blog_public', '0');

        $expected = '';
        foreach (Diagnostics::all() as $check) {
            $expected .= sprintf(
                '<li class="aivis-check aivis-check--%1$s"><span class="aivis-dot aivis-dot--%1$s" aria-hidden="true"></span>'
                . '<span class="aivis-check__body"><span class="aivis-check__label">%2$s</span>'
                . '<span class="aivis-check__detail">%3$s</span></span></li>',
                $check['status'],
                htmlspecialchars($check['label'], ENT_QUOTES),
                htmlspecialchars($check['detail'], ENT_QUOTES),
            );
        }

        expect(($this->markup)())->toContain('<h3 class="aivis-subhead">Checks</h3><ul class="aivis-checks">' . $expected . '</ul>');
    });
});

describe('post type cards', function (): void {
    it('counts the published entries of each type', function (mixed $counts, string $expected): void {
        Functions\when('wp_count_posts')->justReturn($counts);

        expect(($this->markup)())->toContain('<span class="aivis-card-check__count">' . $expected . '</span>');
    })->with([
        'a number' => [(object) ['publish' => 7], '7 published'],
        'a numeric string' => [(object) ['publish' => '12'], '12 published'],
        'one entry' => [(object) ['publish' => 1], '1 published'],
        'no publish key' => [(object) ['draft' => 3], '0 published'],
        'junk in publish' => [(object) ['publish' => 'many'], '0 published'],
        'not an object' => [[], '0 published'],
    ]);

    it('labels a card with the plural name, then the label, then the slug', function (): void {
        $plural = new WP_Post_Type('book', 'Book', 'Books');
        $single = new WP_Post_Type('film', 'Film', 'Films');
        $single->labels = (object) [];
        $bare = new WP_Post_Type('thing', '', '');
        $bare->labels = (object) [];
        $emptyName = new WP_Post_Type('gizmo', 'Gizmo', '');

        Functions\when('get_post_types')->justReturn(['book' => $plural, 'film' => $single, 'thing' => $bare, 'gizmo' => $emptyName]);

        $markup = ($this->markup)();

        expect($markup)->toContain('<span class="aivis-card-check__name">Books</span>')
            ->toContain('<span class="aivis-card-check__name">Film</span>')
            ->toContain('<span class="aivis-card-check__name">thing</span>')
            ->toContain('<span class="aivis-card-check__name">gizmo</span>');
    });

    it('skips attachments but keeps every other type after them', function (): void {
        Functions\when('get_post_types')->justReturn([
            'attachment' => new WP_Post_Type('attachment', 'Media', 'Media'),
            'page' => new WP_Post_Type('page', 'Page', 'Pages'),
        ]);

        $markup = ($this->markup)();

        expect($markup)->toContain('value="page"')->not->toContain('value="attachment"');
    });

    it('asks only for public types', function (): void {
        $asked = null;
        Functions\when('get_post_types')->alias(static function ($args, $output) use (&$asked): array {
            $asked = [$args, $output];

            return [];
        });

        ($this->markup)();

        expect($asked)->toBe([['public' => true], 'objects']);
    });

    it('checks the types that are stored and no other', function (): void {
        Functions\when('get_post_types')->justReturn([
            'post' => new WP_Post_Type('post', 'Post', 'Posts'),
            'page' => new WP_Post_Type('page', 'Page', 'Pages'),
        ]);
        $this->setSettings(['post_types' => ['page']]);

        $markup = ($this->markup)();

        expect($markup)->toContain('value="page" checked=\'checked\'>')
            ->toContain('value="post" >');
    });
});

describe('the identity email', function (): void {
    it('shows the stored address rather than the administrator\'s', function (): void {
        $this->setSettings(['identity_email' => 'me@example.test']);

        expect(($this->markup)())->toContain('name="ai_visibility_settings[identity_email]" value="me@example.test"');
    });

    it('falls back to the administrator address when none is stored', function (): void {
        expect(($this->markup)())->toContain('name="ai_visibility_settings[identity_email]" value="admin@example.test"');
    });

    it('shows nothing when no address can be found anywhere', function (mixed $admin): void {
        $this->setOption('admin_email', $admin);

        expect(($this->markup)())->toContain('name="ai_visibility_settings[identity_email]" value=""');
    })->with([[false], [null], [['a@b.test']]]);
});

describe('the textareas', function (): void {
    it('lists crawlers one per line and ignores non-string entries', function (): void {
        $this->setSettings(['crawlers_allow' => ['A', 'B'], 'crawlers_block' => ['C'], 'identity_socials' => ['https://x.test']]);

        expect(($this->markup)())
            ->toContain('>A' . "\n" . 'B</textarea>')
            ->toContain('>C</textarea>')
            ->toContain('>https://x.test</textarea>');
    });
});

describe('dates and sizes', function (): void {
    it('formats the modification time with the site\'s date and time formats', function (): void {
        $this->setOption('date_format', 'd/m/Y');
        $this->setOption('time_format', 'G\hi');

        expect(($this->markup)())->toContain('<dd>02/01/1971 5h07</dd>');
    });

    it('falls back to ISO formats when the site has none', function (mixed $format): void {
        $this->setOption('date_format', $format);
        $this->setOption('time_format', $format);

        expect(($this->markup)())->toContain('<dd>1971-01-02 05:07</dd>');
    })->with([[false], [['x']]]);

    it('prints the size through size_format', function (): void {
        Functions\when('size_format')->alias(static fn ($b) => '<' . $b . ' bytes>');

        expect(($this->markup)())->toContain('<dd>&lt;12 bytes&gt;</dd>');
    });

    it('prints raw bytes when size_format has no answer', function (): void {
        Functions\when('size_format')->justReturn(false);

        expect(($this->markup)())->toContain('<dd>12 B</dd>');
    });

    it('prints the epoch date when wp_date has no answer', function (): void {
        Functions\when('wp_date')->justReturn(false);

        expect(($this->markup)())->toContain('<dd>1971-01-02 05:07</dd>');
    });
});
