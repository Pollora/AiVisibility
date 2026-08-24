<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Endpoint\DiscoveryFilesEndpoint;
use Pollora\AiVisibility\Endpoint\LlmsTxtEndpoint;

/**
 * The rewrite rules are the contract between a URL and the plugin. A typo in a
 * pattern is invisible until a crawler gets a 404, so the patterns themselves
 * are asserted here and the resulting HTTP responses in tests/e2e/verify.sh.
 */
beforeEach(function (): void {
    $this->rules = [];

    Functions\when('add_rewrite_rule')->alias(function (string $regex, string $query, string $after): void {
        $this->rules[$regex] = ['query' => $query, 'after' => $after];
    });
});

describe('llms.txt endpoint', function (): void {
    beforeEach(function (): void {
        (new LlmsTxtEndpoint())->registerRewriteRules();
    });

    it('registers a rule for each llms file', function (): void {
        expect($this->rules)->toHaveKeys(['^llms\.txt$', '^llms-full\.txt$']);
    });

    it('routes each rule to its own query var value', function (): void {
        expect($this->rules['^llms\.txt$']['query'])->toBe('index.php?ai_visibility_file=llms')
            ->and($this->rules['^llms-full\.txt$']['query'])->toBe('index.php?ai_visibility_file=llms-full');
    });

    it('registers the rules at the top, before any post type rule can claim them', function (): void {
        foreach ($this->rules as $rule) {
            expect($rule['after'])->toBe('top');
        }
    });

    it('anchors the patterns so they cannot match a longer path', function (): void {
        foreach (array_keys($this->rules) as $pattern) {
            expect($pattern)->toStartWith('^')->toEndWith('$');
        }
    });

    it('escapes the dot so llmsXtxt does not match', function (): void {
        expect(preg_match('#^llms\.txt$#', 'llmsXtxt'))->toBe(0)
            ->and(preg_match('#' . '^llms\.txt$' . '#', 'llms.txt'))->toBe(1);
    });

    it('adds its query var without dropping the existing ones', function (): void {
        $vars = (new LlmsTxtEndpoint())->registerQueryVar(['p', 'name']);

        expect($vars)->toBe(['p', 'name', 'ai_visibility_file']);
    });
});

describe('discovery endpoint', function (): void {
    beforeEach(function (): void {
        (new DiscoveryFilesEndpoint())->registerRewriteRules();
    });

    it('registers both the standard and the fallback path for each file', function (): void {
        expect($this->rules)->toHaveKeys([
            '^\.well-known/ai\.txt$',
            '^\.well-known/identity\.json$',
            '^ai-discovery/ai\.txt$',
            '^ai-discovery/identity\.json$',
        ]);
    });

    it('points both paths of a file at the same handler', function (): void {
        expect($this->rules['^\.well-known/ai\.txt$']['query'])
            ->toBe($this->rules['^ai-discovery/ai\.txt$']['query'])
            ->and($this->rules['^\.well-known/identity\.json$']['query'])
            ->toBe($this->rules['^ai-discovery/identity\.json$']['query']);
    });

    it('routes to the artefact slug the handler resolves', function (): void {
        expect($this->rules['^ai-discovery/ai\.txt$']['query'])
            ->toBe('index.php?ai_visibility_discovery=ai-txt')
            ->and($this->rules['^ai-discovery/identity\.json$']['query'])
            ->toBe('index.php?ai_visibility_discovery=identity-json');
    });

    it('registers the rules at the top', function (): void {
        foreach ($this->rules as $rule) {
            expect($rule['after'])->toBe('top');
        }
    });

    it('adds its query var without dropping the existing ones', function (): void {
        $vars = (new DiscoveryFilesEndpoint())->registerQueryVar(['p']);

        expect($vars)->toBe(['p', 'ai_visibility_discovery']);
    });

    it('ignores a request that carries no discovery query var', function (mixed $queryVar): void {
        Functions\when('get_query_var')->justReturn($queryVar);
        // Reaching the file store would mean it tried to serve something.
        Functions\expect('wp_upload_dir')->never();

        (new DiscoveryFilesEndpoint())->handleRequest();
    })->with([
        'absent' => [''],
        'not a string' => [null],
        'unknown slug' => ['llms-txt'],
        'an artefact it does not serve' => ['llms-full-txt'],
    ]);
});

describe('the two endpoints together', function (): void {
    it('claim distinct query vars, so neither can answer for the other', function (): void {
        expect(LlmsTxtEndpoint::QUERY_VAR)->not->toBe(DiscoveryFilesEndpoint::QUERY_VAR);
    });

    it('register no overlapping rewrite pattern', function (): void {
        (new LlmsTxtEndpoint())->registerRewriteRules();
        $llms = array_keys($this->rules);

        $this->rules = [];
        (new DiscoveryFilesEndpoint())->registerRewriteRules();
        $discovery = array_keys($this->rules);

        expect(array_intersect($llms, $discovery))->toBe([]);
    });
});
