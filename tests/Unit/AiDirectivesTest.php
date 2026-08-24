<?php

declare(strict_types=1);

use Pollora\AiVisibility\RobotsTxt\AiDirectives;

beforeEach(function (): void {
    $this->directives = new AiDirectives();
});

it('returns the input untouched when the site is not public', function (): void {
    $this->setSettings(['crawlers_allow' => ['GPTBot']]);

    expect($this->directives->addDirectives("User-agent: *\nDisallow:\n", false))
        ->toBe("User-agent: *\nDisallow:\n");
});

it('appends to the existing robots.txt rather than replacing it', function (): void {
    $existing = "User-agent: *\nDisallow: /wp-admin/\n";

    expect($this->directives->addDirectives($existing, true))->toStartWith($existing);
});

it('emits an Allow block for each permitted crawler', function (): void {
    $this->setSettings(['crawlers_allow' => ['GPTBot', 'ClaudeBot'], 'crawlers_block' => []]);

    $output = $this->directives->addDirectives('', true);

    expect($output)
        ->toHaveLine('User-agent: GPTBot')
        ->toHaveLine('User-agent: ClaudeBot')
        ->toHaveLine('Allow: /')
        ->notToHaveLine('Disallow: /');
});

it('emits a Disallow block for each blocked crawler', function (): void {
    $this->setSettings(['crawlers_allow' => [], 'crawlers_block' => ['CCBot']]);

    $output = $this->directives->addDirectives('', true);

    expect($output)
        ->toHaveLine('User-agent: CCBot')
        ->toHaveLine('Disallow: /')
        ->notToHaveLine('Allow: /');
});

it('pairs every User-agent line with exactly one rule line', function (): void {
    $this->setSettings([
        'crawlers_allow' => ['GPTBot', 'ClaudeBot', 'PerplexityBot'],
        'crawlers_block' => ['CCBot', 'Bytespider'],
    ]);

    $lines = array_values(array_filter(
        explode("\n", $this->directives->addDirectives('', true)),
        static fn (string $line): bool => str_starts_with($line, 'User-agent:')
            || str_starts_with($line, 'Allow:')
            || str_starts_with($line, 'Disallow:'),
    ));

    expect($lines)->toHaveCount(10);

    // Every odd index must be the rule belonging to the agent before it.
    foreach ($lines as $index => $line) {
        $index % 2 === 0
            ? expect($line)->toStartWith('User-agent:')
            : expect($line)->toMatch('/^(Allow|Disallow): \//');
    }
});

it('always points at the discovery files, even with no crawlers configured', function (): void {
    $this->setSettings(['crawlers_allow' => [], 'crawlers_block' => []]);

    expect($this->directives->addDirectives('', true))
        ->toContain('https://example.test/llms.txt')
        ->toContain('https://example.test/llms-full.txt');
});

it('comments out the discovery pointers so no crawler reads them as directives', function (): void {
    $this->setSettings([]);

    $output = $this->directives->addDirectives('', true);

    foreach (explode("\n", $output) as $line) {
        if (str_contains($line, 'llms.txt') || str_contains($line, 'llms-full.txt')) {
            expect($line)->toStartWith('#');
        }
    }
});

it('ends with a newline so a later filter can append safely', function (): void {
    $this->setSettings([]);

    expect($this->directives->addDirectives('', true))->toEndWith("\n");
});

it('accepts the string blog_public value WordPress actually passes', function (): void {
    // do_robots() forwards get_option('blog_public'), which is "1" or "0".
    $this->setSettings(['crawlers_allow' => ['GPTBot']]);

    expect($this->directives->addDirectives('', '1'))->toContain('GPTBot');
    expect($this->directives->addDirectives('', '0'))->toBe('');
});
