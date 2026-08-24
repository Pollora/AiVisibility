<?php

declare(strict_types=1);

use Brain\Monkey\Filters as MonkeyFilters;
use Brain\Monkey\Functions;
use Pollora\AiVisibility\Generator\LlmsFullTxtGenerator;

beforeEach(function (): void {
    $this->generator = new LlmsFullTxtGenerator();

    Functions\when('clean_post_cache')->justReturn(null);
    Functions\when('get_the_date')->justReturn('2026-01-15');
    Functions\when('get_the_modified_date')->justReturn('2026-01-16');
});

it('opens with the site name and tagline', function (): void {
    $this->setSettings(['post_types' => []]);
    stubCorpus([]);

    expect($this->generator->generate())
        ->toStartWith('# Example Site')
        ->toHaveLine('> Just another example');
});

it('emits a section per post, separated by a horizontal rule', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [
        $this->post(1, 'First', '<p>Body one</p>'),
        $this->post(2, 'Second', '<p>Body two</p>'),
    ]]);

    $output = $this->generator->generate();

    expect($output)
        ->toHaveLine('## First')
        ->toHaveLine('## Second')
        ->toHaveLine('---')
        ->toHaveLine('URL: https://example.test/first')
        ->toContain('Body one')
        ->toContain('Body two');
});

it('includes the converted body, not the raw HTML', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [
        $this->post(1, 'Article', '<h2>Section</h2><p>Some <strong>bold</strong> text.</p>'),
    ]]);

    expect($this->generator->generate())
        ->toHaveLine('## Section')
        ->toContain('**bold**')
        ->not->toContain('<strong>');
});

it('skips a post whose body converts to nothing', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [
        $this->post(1, 'Empty', '   '),
        $this->post(2, 'Real', '<p>Content</p>'),
    ]]);

    expect($this->generator->generate())
        ->notToHaveLine('## Empty')
        ->toHaveLine('## Real');
});

it('excludes noindex posts', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(
        ['post' => [$this->post(1, 'Public', '<p>Visible</p>'), $this->post(2, 'Hidden', '<p>SECRET</p>')]],
        [2 => '1'],
    );

    expect($this->generator->generate())->toContain('Visible')->not->toContain('SECRET');
});

it('excludes password-protected posts', function (): void {
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [
        $this->post(1, 'Public', '<p>Visible</p>'),
        $this->post(2, 'Locked', '<p>SECRET</p>', ['post_password' => 'hunter2']),
    ]]);

    expect($this->generator->generate())->toContain('Visible')->not->toContain('SECRET');
});

it('skips a post type that is not registered', function (): void {
    $this->setSettings(['post_types' => ['ghost']]);
    stubCorpus(['post' => [$this->post(1, 'Real', '<p>Content</p>')]]);

    expect($this->generator->generate())->not->toContain('Real');
});

it('frees each post from the object cache as it goes', function (): void {
    // Without this a large site exhausts memory partway through generation.
    $this->setSettings(['post_types' => ['post']]);
    stubCorpus(['post' => [$this->post(1, 'One', '<p>a</p>'), $this->post(2, 'Two', '<p>b</p>')]]);

    $cleaned = [];
    Functions\when('clean_post_cache')->alias(static function (int $id) use (&$cleaned): void {
        $cleaned[] = $id;
    });

    $this->generator->generate();

    expect($cleaned)->toBe([1, 2]);
});

describe('extension points', function (): void {
    beforeEach(function (): void {
        $this->setSettings(['post_types' => ['post']]);
        stubCorpus(['post' => [$this->post(1, 'Article', '<p>Body</p>')]]);
    });

    it('appends custom metadata lines', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_markdown_meta')
            ->once()
            ->andReturn(['Location' => 'Angres', 'Attendees' => 42]);

        expect($this->generator->generate())
            ->toHaveLine('Location: Angres')
            ->toHaveLine('Attendees: 42');
    });

    it('inserts text before the body', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_markdown_before_content')
            ->once()
            ->andReturn('A structured summary.');

        expect($this->generator->generate())->toHaveLine('A structured summary.');
    });

    it('lets a filter replace the converted body', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_markdown_content')
            ->once()
            ->andReturn('Replaced body.');

        expect($this->generator->generate())->toHaveLine('Replaced body.');
    });

    it('lets a filter replace the whole document', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_llms_full_txt')->once()->andReturn('overridden');

        expect($this->generator->generate())->toBe('overridden');
    });
});
