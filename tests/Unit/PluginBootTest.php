<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Plugin;

beforeEach(function (): void {
    Functions\when('is_admin')->justReturn(false);

    $this->boot = function (array $settings = []): void {
        $this->setSettings($settings);
        (new Plugin())->boot();
    };
});

/**
 * has_action()/has_filter() answer with the priority, or false. Callers here
 * only care whether the callback is attached at all.
 */
function isHooked(string $hook, string $callback): bool
{
    return has_action($hook, $callback) !== false || has_filter($hook, $callback) !== false;
}

describe('feature flags', function (): void {
    it('registers the llms.txt endpoint only when enabled', function (bool $enabled): void {
        ($this->boot)(['enable_llms_txt' => $enabled, 'enable_markdown' => false, 'enable_discovery' => false]);

        expect(isHooked('init', 'Pollora\AiVisibility\Endpoint\LlmsTxtEndpoint->registerRewriteRules()'))
            ->toBe($enabled);
    })->with([true, false]);

    it('registers the Markdown endpoint only when enabled', function (bool $enabled): void {
        ($this->boot)(['enable_markdown' => $enabled, 'enable_llms_txt' => false, 'enable_discovery' => false]);

        expect(isHooked('init', 'Pollora\AiVisibility\Endpoint\MarkdownEndpoint->registerRewriteRules()'))
            ->toBe($enabled);
    })->with([true, false]);

    it('registers the discovery endpoint only when enabled', function (bool $enabled): void {
        ($this->boot)(['enable_discovery' => $enabled, 'enable_llms_txt' => false, 'enable_markdown' => false]);

        expect(isHooked('init', 'Pollora\AiVisibility\Endpoint\DiscoveryFilesEndpoint->registerRewriteRules()'))
            ->toBe($enabled);
    })->with([true, false]);

    it('hooks robots.txt only when enabled', function (bool $enabled): void {
        ($this->boot)(['enable_robots' => $enabled]);

        expect(isHooked('robots_txt', 'Pollora\AiVisibility\RobotsTxt\AiDirectives->addDirectives()'))
            ->toBe($enabled);
    })->with([true, false]);

    it('does not register abilities when the feature is switched off', function (): void {
        ($this->boot)(['enable_abilities' => false]);

        expect(has_action('wp_abilities_api_init'))->toBeFalse()
            ->and(has_action('wp_abilities_api_categories_init'))->toBeFalse();
    });
});

describe('always-on wiring', function (): void {
    beforeEach(function (): void {
        ($this->boot)([]);
    });

    it('schedules regeneration when a post is saved or deleted', function (): void {
        expect(has_action('save_post', 'Pollora\AiVisibility\Cache\Invalidation->scheduleRegeneration()'))->toBe(20)
            ->and(has_action('delete_post', 'Pollora\AiVisibility\Cache\Invalidation->scheduleRegeneration()'))->toBe(20);
    });

    it('listens on the cron hook', function (): void {
        expect(has_action('ai_visibility_regenerate', 'Pollora\AiVisibility\Cache\Invalidation->regenerate()'))
            ->not->toBeFalse();
    });

    it('guards the canonical redirect', function (): void {
        expect(has_filter('redirect_canonical', 'Pollora\AiVisibility\Plugin->preventTrailingSlash()'))->toBe(10);
    });

    it('flushes rewrite rules late on init, after the rules are registered', function (): void {
        expect(has_action('init', 'Pollora\AiVisibility\Plugin->maybeFlushRewrites()'))->toBe(99);
    });
});

it('registers the admin screen only inside wp-admin', function (bool $isAdmin): void {
    Functions\when('is_admin')->justReturn($isAdmin);

    ($this->boot)([]);

    expect(isHooked('admin_menu', 'Pollora\AiVisibility\Admin\SettingsPage->register()'))->toBe($isAdmin);
})->with([true, false]);

describe('preventTrailingSlash', function (): void {
    beforeEach(function (): void {
        $this->plugin = new Plugin();
    });

    it('cancels the redirect for a plugin endpoint', function (string $url): void {
        expect($this->plugin->preventTrailingSlash('https://example.test/redirected/', $url))->toBeFalse();
    })->with([
        'https://example.test/llms.txt',
        'https://example.test/llms-full.txt',
        'https://example.test/.well-known/ai.txt',
        'https://example.test/.well-known/identity.json',
        'https://example.test/ai-discovery/ai.txt',
        'https://example.test/ai-discovery/identity.json',
        'https://example.test/some/post.md',
        'https://example.test/index.md',
        'https://example.test/nested/path/page.md?utm_source=x',
    ]);

    it('leaves ordinary URLs to WordPress', function (string $url): void {
        expect($this->plugin->preventTrailingSlash('https://example.test/canonical/', $url))
            ->toBe('https://example.test/canonical/');
    })->with([
        'https://example.test/a-normal-post',
        'https://example.test/llms.txt.html',
        'https://example.test/markdown-tips/',
        'https://example.test/',
    ]);

    it('passes a false redirect straight through', function (): void {
        expect($this->plugin->preventTrailingSlash(false, 'https://example.test/anything'))->toBeFalse();
    });

    it('does not choke on an unparseable URL', function (): void {
        expect($this->plugin->preventTrailingSlash('https://example.test/x/', 'http://:'))
            ->toBe('https://example.test/x/');
    });
});

describe('maybeFlushRewrites', function (): void {
    it('flushes once and clears the marker', function (): void {
        $this->setOption('ai_visibility_flush_rewrite', true);
        Functions\expect('flush_rewrite_rules')->once();

        (new Plugin())->maybeFlushRewrites();

        expect($this->optionValues)->not->toHaveKey('ai_visibility_flush_rewrite');
    });

    it('does nothing on a normal request', function (): void {
        Functions\expect('flush_rewrite_rules')->never();

        (new Plugin())->maybeFlushRewrites();
    });
});

describe('uploadDir', function (): void {
    it('returns null when the uploads directory reports an error', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'Disk full', 'basedir' => '/tmp']);

        expect(Plugin::uploadDir())->toBeNull()
            ->and(Plugin::filePath('llms.txt'))->toBeNull();
    });

    it('returns null when the directory cannot be created', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['error' => false, 'basedir' => '/proc/nonexistent']);
        Functions\when('wp_mkdir_p')->justReturn(false);

        expect(Plugin::uploadDir())->toBeNull();
    });
});
