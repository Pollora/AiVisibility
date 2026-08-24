<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Tests\TestCase;

uses(TestCase::class)->in('Unit');

/**
 * Assert that a Markdown document contains a line exactly.
 *
 * Substring assertions on generated Markdown hide indentation and prefix bugs:
 * `->toContain('# Title')` also passes for `## Title`. This does not.
 */
expect()->extend('toHaveLine', function (string $line) {
    $lines = explode("\n", (string) $this->value);

    expect($lines)->toContain($line);

    return $this;
});

/**
 * Assert that a Markdown document does not contain a line exactly.
 */
expect()->extend('notToHaveLine', function (string $line) {
    $lines = explode("\n", (string) $this->value);

    expect($lines)->not->toContain($line);

    return $this;
});

/**
 * Wires up just enough of the post API for the generator to walk a corpus.
 *
 * @param  array<string, list<WP_Post>>  $corpus  post type => posts
 */
function stubCorpus(array $corpus, array $noindex = []): void
{
    $byId = [];

    foreach ($corpus as $posts) {
        foreach ($posts as $post) {
            $byId[$post->ID] = $post;
        }
    }

    Functions\when('get_post_type_object')->alias(
        static fn (string $type) => isset($corpus[$type])
            ? new WP_Post_Type($type, ucfirst($type), ucfirst($type) . 's')
            : null,
    );
    Functions\when('is_post_type_hierarchical')->alias(static fn (string $type): bool => $type === 'page');
    Functions\when('get_posts')->alias(static function (array $args) use ($corpus): array {
        $posts = $corpus[$args['post_type']] ?? [];

        return array_map(static fn (WP_Post $p): int => $p->ID, array_slice($posts, 0, $args['posts_per_page']));
    });
    Functions\when('get_post')->alias(static fn (int $id) => $byId[$id] ?? null);
    Functions\when('get_the_title')->alias(
        static fn ($p) => ($p instanceof WP_Post ? $p : $byId[$p] ?? null)?->post_title ?? '',
    );
    Functions\when('get_permalink')->alias(
        static fn ($p) => 'https://example.test/' . (($p instanceof WP_Post ? $p : $byId[$p] ?? null)?->post_name ?? ''),
    );
    Functions\when('post_password_required')->alias(static fn (WP_Post $p): bool => $p->post_password !== '');
    Functions\when('get_post_meta')->alias(
        static fn (int $id, string $key) => $noindex[$id] ?? ($key === 'rank_math_robots' ? [] : ''),
    );
    Functions\when('wp_trim_words')->alias(static fn (string $t) => $t);
}
