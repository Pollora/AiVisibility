<?php

declare(strict_types=1);

use Brain\Monkey\Filters as MonkeyFilters;
use Brain\Monkey\Functions;
use Pollora\AiVisibility\Generator\LlmsTxtGenerator;

beforeEach(function (): void {
    $this->generator = new LlmsTxtGenerator();
});

it('opens with the site name as an H1, as the spec requires', function (): void {
    $this->setSettings(['post_types' => []]);
    stubCorpus([]);

    expect($this->generator->generate())->toStartWith('# Example Site');
});

it('puts the tagline in a blockquote', function (): void {
    $this->setSettings(['post_types' => []]);
    stubCorpus([]);

    expect($this->generator->generate())->toHaveLine('> Just another example');
});

it('includes the configured site description', function (): void {
    $this->setSettings(['post_types' => [], 'site_description' => 'What this site is about.']);
    stubCorpus([]);

    expect($this->generator->generate())->toHaveLine('What this site is about.');
});

it('groups entries under a heading per post type', function (): void {
    $this->setSettings(['post_types' => ['post', 'page']]);
    stubCorpus([
        'post' => [$this->post(1, 'First post')],
        'page' => [$this->post(2, 'About')],
    ]);

    expect($this->generator->generate())->toHaveLine('## Posts')->toHaveLine('## Pages');
});

it('links to the .md variant, not the HTML page', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [$this->post(1, 'First post')]]);

    expect($this->generator->generate())->toHaveLine('- [First post](https://example.test/first-post.md)');
});

it('appends the SEO description after a colon', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [$this->post(1, 'First post', '', ['post_excerpt' => 'A short summary'])]]);

    expect($this->generator->generate())
        ->toHaveLine('- [First post](https://example.test/first-post.md): A short summary');
});

it('skips a post type that is not registered', function (): void {
    $this->setSettings(['post_types' => ['post', 'ghost']]);
    stubCorpus(['post' => [$this->post(1, 'First post')]]);

    expect($this->generator->generate())->toHaveLine('## Posts')->not->toContain('ghost');
});

it('omits the heading when a type has no publishable posts', function (): void {
    $this->setSettings(['post_types' => ['post', 'page']]);
    stubCorpus(['post' => [$this->post(1, 'First post')], 'page' => []]);

    expect($this->generator->generate())->toHaveLine('## Posts')->notToHaveLine('## Pages');
});

it('excludes noindex posts', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(
        ['post' => [$this->post(1, 'Public'), $this->post(2, 'Hidden')]],
        [2 => '1'],
    );

    $output = $this->generator->generate();

    expect($output)->toContain('Public')->not->toContain('Hidden');
});

it('excludes password-protected posts', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [
        $this->post(1, 'Public'),
        $this->post(2, 'Members only', '', ['post_password' => 'hunter2']),
    ]]);

    expect($this->generator->generate())->toContain('Public')->not->toContain('Members only');
});

it('honours the per-type limit', function (): void {
    $this->setSettings(['post_types' => ['post'], 'posts_per_type' => 2]);
    stubCorpus(['post' => [
        $this->post(1, 'One'),
        $this->post(2, 'Two'),
        $this->post(3, 'Three'),
    ]]);

    expect($this->generator->generate())->toContain('One')->toContain('Two')->not->toContain('Three');
});

it('decodes HTML entities in titles', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [$this->post(1, 'Caf&eacute; &amp; cr&egrave;me')]]);

    expect($this->generator->generate())->toContain('Café & crème')->not->toContain('&amp;');
});

it('falls back to the post type key when the labels object is malformed', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [$this->post(1, 'A post')]]);

    Functions\when('get_post_type_object')->alias(static function (string $type): WP_Post_Type {
        $object = new WP_Post_Type($type, '');
        $object->labels = (object) [];

        return $object;
    });

    expect($this->generator->generate())->toHaveLine('## post');
});

it('is filterable', function (): void {
    $this->setSettings(['post_types' => []]);
    stubCorpus([]);
    MonkeyFilters\expectApplied('ai_visibility_llms_txt')->once()->andReturn('overridden');

    expect($this->generator->generate())->toBe('overridden');
});
