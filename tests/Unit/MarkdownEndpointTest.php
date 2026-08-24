<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Endpoint\MarkdownEndpoint;

beforeEach(function (): void {
    $this->endpoint = new MarkdownEndpoint();

    Functions\when('get_permalink')->alias(
        static fn ($p) => $p instanceof WP_Post
            ? 'https://example.test/' . $p->post_name . '/'
            : 'https://example.test/post-' . $p . '/',
    );
});

describe('getMdUrl', function (): void {
    it('appends .md to a permalink, replacing the trailing slash', function (): void {
        $post = $this->post(7, 'My Post');

        expect(MarkdownEndpoint::getMdUrl($post))->toBe('https://example.test/my-post.md');
    });

    it('accepts a bare post ID', function (): void {
        expect(MarkdownEndpoint::getMdUrl(7))->toBe('https://example.test/post-7.md');
    });

    it('maps the static front page to /index.md', function (): void {
        $this->setOption('page_on_front', '12');
        Functions\when('get_post')->justReturn($this->post(12, 'Home'));

        expect(MarkdownEndpoint::getMdUrl(12))->toBe('https://example.test/index.md');
    });

    it('does not treat post 0 as the front page when none is set', function (): void {
        $this->setOption('page_on_front', '0');

        expect(MarkdownEndpoint::getMdUrl(7))->toBe('https://example.test/post-7.md');
    });

    it('returns an empty string when the post has no permalink', function (): void {
        Functions\when('get_permalink')->justReturn(false);

        expect(MarkdownEndpoint::getMdUrl(999))->toBe('');
    });

    it('keeps a hierarchical path intact', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.test/parent/child/');

        expect(MarkdownEndpoint::getMdUrl(3))->toBe('https://example.test/parent/child.md');
    });
});

describe('query var registration', function (): void {
    it('adds format without dropping the vars WordPress already registered', function (): void {
        $vars = $this->endpoint->registerQueryVar(['p', 'name', 'page_id']);

        expect($vars)->toBe(['p', 'name', 'page_id', 'format']);
    });
});

describe('invalidateCache', function (): void {
    it('deletes the transient for exactly that post', function (): void {
        Functions\expect('delete_transient')->once()->with('ai_vis_md_512');

        MarkdownEndpoint::invalidateCache(512);
    });
});

describe('alternate advertisement', function (): void {
    beforeEach(function (): void {
        Functions\when('get_query_var')->justReturn('');
        Functions\when('get_post_meta')->justReturn('');
    });

    it('advertises nothing on an archive or the home page', function (): void {
        Functions\when('is_singular')->justReturn(false);
        Functions\expect('esc_url')->never();

        $this->endpoint->renderLinkTag();

        expect(true)->toBeTrue();
    });

    it('renders an alternate link for an ordinary post', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn($this->post(7, 'My Post'));

        ob_start();
        $this->endpoint->renderLinkTag();
        $output = (string) ob_get_clean();

        expect($output)
            ->toContain('rel="alternate"')
            ->toContain('type="text/markdown"')
            ->toContain('https://example.test/my-post.md');
    });

    it('advertises nothing for a password-protected post', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn(
            $this->post(7, 'Secret', '', ['post_password' => 'hunter2']),
        );

        ob_start();
        $this->endpoint->renderLinkTag();

        expect((string) ob_get_clean())->toBe('');
    });

    it('advertises nothing for a noindex post', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn($this->post(7, 'Hidden'));
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key) => $key === '_yoast_wpseo_meta-robots-noindex' ? '1' : '',
        );

        ob_start();
        $this->endpoint->renderLinkTag();

        expect((string) ob_get_clean())->toBe('');
    });

    it('advertises nothing when the queried object is not a post', function (): void {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object')->justReturn(null);

        ob_start();
        $this->endpoint->renderLinkTag();

        expect((string) ob_get_clean())->toBe('');
    });
});

describe('request routing', function (): void {
    beforeEach(function (): void {
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('get_queried_object')->justReturn(null);
    });

    it('serves the queried post on a singular request', function (): void {
        $post = $this->post(7, 'My Post');
        Functions\when('get_queried_object')->justReturn($post);

        expect(MarkdownEndpoint::requestedPost())->toBe($post);
    });

    it('resolves the static front page itself on the home request', function (): void {
        // WP_Query does not substitute page_on_front once an extra query var is
        // present, so the queried object is not the page and core hands us the
        // blog index instead.
        Functions\when('is_home')->justReturn(true);
        $this->setOption('page_on_front', '12');

        $front = $this->post(12, 'Welcome');
        Functions\when('get_post')->justReturn($front);

        expect(MarkdownEndpoint::requestedPost())->toBe($front);
    });

    it('resolves nothing on the home request when there is no static front page', function (string $stored): void {
        Functions\when('is_home')->justReturn(true);
        $this->setOption('page_on_front', $stored);

        expect(MarkdownEndpoint::requestedPost())->toBeNull();
    })->with(['0', '', 'not-a-number']);

    it('never serves the front page in place of an archive', function (): void {
        // On a category archive the queried object is a term, not a post.
        Functions\when('get_queried_object')->justReturn(new stdClass());
        $this->setOption('page_on_front', '12');
        Functions\expect('get_post')->never();

        expect(MarkdownEndpoint::requestedPost())->toBeNull();
    });

    it('resolves nothing on a 404', function (): void {
        expect(MarkdownEndpoint::requestedPost())->toBeNull();
    });
});
