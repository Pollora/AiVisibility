<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Generator\SeoIntegration;

beforeEach(function (): void {
    $this->seo = new SeoIntegration();

    /** @var array<string, mixed> $meta */
    $this->meta = [];

    Functions\when('get_post_meta')->alias(
        fn (int $id, string $key, bool $single = false) => $this->meta[$key] ?? '',
    );
    Functions\when('get_post')->justReturn(null);

    // Default: The SEO Framework is not installed. Declared explicitly because
    // Brain Monkey leaves a mocked function defined for the rest of the process,
    // so function_exists() alone would leak between tests.
    Functions\when('the_seo_framework')->justReturn(null);
    Functions\when('wp_trim_words')->alias(
        static fn (string $text, int $words) => implode(' ', array_slice(explode(' ', $text), 0, $words)),
    );
});

describe('getDescription', function (): void {
    it('reads the description from each supported SEO plugin', function (string $metaKey): void {
        $this->meta = [$metaKey => 'The configured description'];

        expect($this->seo->getDescription(1))->toBe('The configured description');
    })->with([
        'Yoast' => '_yoast_wpseo_metadesc',
        'Rank Math' => 'rank_math_description',
        'SEOPress' => '_seopress_titles_desc',
    ]);

    it('prefers Yoast over Rank Math over SEOPress', function (): void {
        $this->meta = [
            '_yoast_wpseo_metadesc' => 'Yoast wins',
            'rank_math_description' => 'Rank Math',
            '_seopress_titles_desc' => 'SEOPress',
        ];

        expect($this->seo->getDescription(1))->toBe('Yoast wins');
    });

    it('ignores an empty value and moves to the next plugin', function (): void {
        $this->meta = [
            '_yoast_wpseo_metadesc' => '',
            'rank_math_description' => 'Rank Math description',
        ];

        expect($this->seo->getDescription(1))->toBe('Rank Math description');
    });

    it('falls back to the excerpt', function (): void {
        Functions\when('get_post')->justReturn(
            $this->post(1, 'Title', 'Body text', ['post_excerpt' => 'The excerpt']),
        );

        expect($this->seo->getDescription(1))->toBe('The excerpt');
    });

    it('falls back to trimmed content when there is no excerpt', function (): void {
        $content = implode(' ', array_map(static fn (int $i): string => "word{$i}", range(1, 50)));
        Functions\when('get_post')->justReturn($this->post(1, 'Title', $content));

        $description = $this->seo->getDescription(1);

        expect(explode(' ', $description))->toHaveCount(30);
    });

    it('returns an empty string when the post does not exist', function (): void {
        expect($this->seo->getDescription(999))->toBe('');
    });

    it('ignores a non-string meta value', function (): void {
        $this->meta = ['_yoast_wpseo_metadesc' => ['unexpected']];

        expect($this->seo->getDescription(1))->toBe('');
    });

    it('probes The SEO Framework rather than assuming its API', function (): void {
        Functions\when('the_seo_framework')->justReturn(new class () {
            public function get_description(array $args): string
            {
                return 'From TSF';
            }
        });

        expect($this->seo->getDescription(1))->toBe('From TSF');
    });

    it('moves on when The SEO Framework facade lacks the expected method', function (): void {
        Functions\when('the_seo_framework')->justReturn(new stdClass());
        $this->meta = ['_yoast_wpseo_metadesc' => 'Yoast description'];

        expect($this->seo->getDescription(1))->toBe('Yoast description');
    });
});

describe('isNoindex', function (): void {
    it('detects noindex for each supported SEO plugin', function (string $key, mixed $value): void {
        $this->meta = [$key => $value];

        expect($this->seo->isNoindex(1))->toBeTrue();
    })->with([
        'The SEO Framework' => ['_genesis_noindex', '1'],
        'Yoast' => ['_yoast_wpseo_meta-robots-noindex', '1'],
        'Rank Math array' => ['rank_math_robots', ['noindex', 'nofollow']],
        'Rank Math string' => ['rank_math_robots', 'noindex,nofollow'],
        'SEOPress' => ['_seopress_robots_index', 'yes'],
    ]);

    it('reports indexable when nothing marks the post as hidden', function (): void {
        $this->meta = [];

        expect($this->seo->isNoindex(1))->toBeFalse();
    });

    it('does not mistake an explicit index directive for noindex', function (mixed $value): void {
        $this->meta = ['rank_math_robots' => $value];

        expect($this->seo->isNoindex(1))->toBeFalse();
    })->with([
        'array' => [['index', 'follow']],
        'string' => ['index,follow'],
        'empty array' => [[]],
    ]);

    it('treats an explicit zero as indexable', function (string $key): void {
        $this->meta = [$key => '0'];

        expect($this->seo->isNoindex(1))->toBeFalse();
    })->with(['_genesis_noindex', '_yoast_wpseo_meta-robots-noindex']);
});
