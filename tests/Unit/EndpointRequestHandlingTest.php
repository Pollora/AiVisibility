<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Endpoint\DiscoveryFilesEndpoint;
use Pollora\AiVisibility\Endpoint\LlmsTxtEndpoint;
use Pollora\AiVisibility\Endpoint\MarkdownEndpoint;

/**
 * Invoke a private endpoint method. The serving paths end in exit, so the
 * decisions that precede it are exercised through the helpers that carry them.
 */
function callPrivate(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($object, $method);

    return $reflection->invoke($object, ...$args);
}

describe('LlmsTxtEndpoint::handleRequest', function (): void {
    it('lets the request through when the query var names no known file', function (mixed $value): void {
        Functions\when('get_query_var')->justReturn($value);
        Functions\expect('wp_upload_dir')->never();

        (new LlmsTxtEndpoint())->handleRequest();

        expect(true)->toBeTrue();
    })->with(['', 'ai', 'LLMS', null, 0]);

    it('maps the query var to the file it stands for', function (string $var, string $file): void {
        $artifact = callPrivate(new LlmsTxtEndpoint(), 'resolve', $var);
        // resolve() is static; ReflectionMethod::invoke tolerates a throwaway instance.
        expect($artifact?->value)->toBe($file);
    })->with([['llms', 'llms.txt'], ['llms-full', 'llms-full.txt']]);
});

describe('DiscoveryFilesEndpoint::handleRequest', function (): void {
    it('ignores a slug that is not a discovery file', function (string $slug): void {
        Functions\when('get_query_var')->justReturn($slug);
        Functions\expect('wp_upload_dir')->never();

        (new DiscoveryFilesEndpoint())->handleRequest();

        expect(true)->toBeTrue();
    })->with(['unknown', 'llms.txt', 'llms-full.txt']);
});

describe('MarkdownEndpoint request interception', function (): void {
    it('ignores a path that is not a Markdown request', function (): void {
        Functions\expect('get_post_types')->never();

        (new MarkdownEndpoint())->interceptMdRequest(new WP('blog/my-post'));

        expect(true)->toBeTrue();
    });

    it('answers /index.md with nothing when no static front page exists', function (): void {
        $this->setOption('page_on_front', '0');
        Functions\expect('get_post')->never();

        (new MarkdownEndpoint())->interceptMdRequest(new WP('index.md'));

        expect(true)->toBeTrue();
    });

    it('does nothing for ?format=md unless the query var says md', function (string $format): void {
        Functions\when('get_query_var')->justReturn($format);
        Functions\expect('get_queried_object')->never();

        (new MarkdownEndpoint())->handleRequest();

        expect(true)->toBeTrue();
    })->with(['', 'json', 'MD']);

    it('hooks the alternate advertisement onto the front-end actions', function (): void {
        (new MarkdownEndpoint())->registerQueryVars();
        (new MarkdownEndpoint())->registerRewriteRules();

        expect(has_action('template_redirect'))->not->toBeFalse()
            ->and(has_action('wp_head'))->not->toBeFalse()
            ->and(has_action('parse_request'))->not->toBeFalse()
            ->and(has_filter('query_vars'))->not->toBeFalse();
    });
});

describe('MarkdownEndpoint::sendLinkHeader', function (): void {
    it('stays silent outside singular views', function (): void {
        Functions\when('is_singular')->justReturn(false);
        Functions\expect('get_queried_object')->never();

        (new MarkdownEndpoint())->sendLinkHeader();

        expect(true)->toBeTrue();
    });

    it('stays silent when the Markdown version is the one being served', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_query_var')->justReturn('md');
        Functions\expect('get_queried_object')->never();

        (new MarkdownEndpoint())->sendLinkHeader();

        expect(true)->toBeTrue();
    });

    it('stays silent for a post with no public Markdown twin', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_query_var')->justReturn('');
        Functions\when('get_queried_object')->justReturn(
            $this->post(7, 'Secret', '', ['post_password' => 'x']),
        );

        (new MarkdownEndpoint())->sendLinkHeader();

        expect(true)->toBeTrue();
    });
});

describe('MarkdownEndpoint::findPostByPath', function (): void {
    beforeEach(function (): void {
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page']);
    });

    it('returns the published page found by its full path', function (): void {
        $page = $this->post(3, 'Child', '', ['post_type' => 'page']);
        Functions\when('get_page_by_path')->justReturn($page);
        Functions\expect('get_posts')->never();

        expect(callPrivate(new MarkdownEndpoint(), 'findPostByPath', 'parent/child'))->toBe($page);
    });

    it('falls back to the last slug segment when the path lookup misses', function (): void {
        $post = $this->post(4, 'Child');
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\expect('get_posts')->once()->withArgs(
            static fn (array $args): bool => $args['name'] === 'child'
                && $args['post_type'] === ['post', 'page']
                && $args['post_status'] === 'publish',
        )->andReturn([$post]);

        expect(callPrivate(new MarkdownEndpoint(), 'findPostByPath', 'old/parent/child'))->toBe($post);
    });

    it('does not serve an unpublished page found by path', function (): void {
        $draft = $this->post(5, 'Draft', '', ['post_status' => 'draft']);
        Functions\when('get_page_by_path')->justReturn($draft);
        Functions\when('get_posts')->justReturn([]);

        expect(callPrivate(new MarkdownEndpoint(), 'findPostByPath', 'draft'))->toBeNull();
    });

    it('ignores a fallback result that is not a post', function (): void {
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('get_posts')->justReturn([42]);

        expect(callPrivate(new MarkdownEndpoint(), 'findPostByPath', 'nothing'))->toBeNull();
    });
});

describe('MarkdownEndpoint::buildMarkdown', function (): void {
    beforeEach(function (): void {
        Functions\when('get_the_title')->alias(static fn ($p) => $p->post_title);
        Functions\when('get_permalink')->justReturn('https://example.test/my-post/');
        Functions\when('get_the_date')->justReturn('2026-01-02');
        Functions\when('get_the_modified_date')->justReturn('2026-02-03');
        Functions\when('post_password_required')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('wp_trim_words')->alias(static fn (string $t) => $t);

        $this->build = function (\WP_Post $post): string {
            Functions\when('get_post')->justReturn($post);

            return callPrivate(
                new MarkdownEndpoint(),
                'buildMarkdown',
                $post,
                new Pollora\AiVisibility\Generator\MarkdownConverter(),
                new Pollora\AiVisibility\Generator\SeoIntegration(),
            );
        };
    });

    it('opens with the decoded title, then the standard metadata', function (): void {
        $markdown = ($this->build)($this->post(7, 'Fish &amp; Chips', '<p>Body text</p>'));

        expect($markdown)
            ->toHaveLine('# Fish & Chips')
            ->toHaveLine('URL: https://example.test/my-post/')
            ->toHaveLine('Date: 2026-01-02')
            ->toHaveLine('Modified: 2026-02-03')
            ->toHaveLine('Body text');
    });

    it('quotes the description under the title when the SEO plugin provides one', function (): void {
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key) => $key === '_yoast_wpseo_metadesc' ? 'A short summary' : '',
        );

        expect(($this->build)($this->post(7, 'My Post')))->toHaveLine('> A short summary');
    });

    it('appends custom metadata and inserts before-content ahead of the body', function (): void {
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value) {
            return match ($hook) {
                'ai_visibility_markdown_meta' => ['Location' => 'Paris', 'Seats' => 12, 'Empty' => ''],
                'ai_visibility_markdown_before_content' => '**Summary**',
                default => $value,
            };
        });

        $markdown = ($this->build)($this->post(7, 'My Post', '<p>Body</p>'));

        expect($markdown)
            ->toHaveLine('Location: Paris')
            ->toHaveLine('Seats: 12')
            ->notToHaveLine('Empty: ')
            ->and(strpos($markdown, '**Summary**'))->toBeLessThan(strrpos($markdown, 'Body'));
    });

    it('lets the final filter replace the whole document', function (): void {
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value) => $hook === 'ai_visibility_markdown' ? 'REPLACED' : $value,
        );

        expect(($this->build)($this->post(7, 'My Post')))->toBe('REPLACED');
    });

    it('omits the body line when the content converts to nothing', function (): void {
        $markdown = ($this->build)($this->post(7, 'My Post', ''));

        expect(rtrim($markdown))->toEndWith('Modified: 2026-02-03');
    });
});
