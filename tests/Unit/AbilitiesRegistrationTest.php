<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Abilities\Registration;

beforeEach(function (): void {
    $this->abilities = [];
    $this->categories = [];

    Functions\when('wp_register_ability')->alias(function (string $name, array $args) {
        $this->abilities[$name] = $args;

        return null;
    });
    Functions\when('wp_register_ability_category')->alias(function (string $slug, array $args) {
        $this->categories[$slug] = $args;

        return null;
    });

    (new Registration())->registerCategories();
    (new Registration())->registerAbilities();
});

it('registers a single namespaced category', function (): void {
    expect($this->categories)->toHaveCount(1)
        ->and($this->categories)->toHaveKey('ai-visibility')
        ->and($this->categories['ai-visibility'])->toHaveKeys(['label', 'description']);
});

it('registers exactly the three documented abilities', function (): void {
    expect(array_keys($this->abilities))->toBe([
        'ai-visibility/get-llms-txt',
        'ai-visibility/regenerate',
        'ai-visibility/get-site-summary',
    ]);
});

it('namespaces every ability under the plugin', function (): void {
    foreach (array_keys($this->abilities) as $name) {
        expect($name)->toStartWith('ai-visibility/');
    }
});

it('files every ability under its own category', function (): void {
    foreach ($this->abilities as $ability) {
        expect($ability['category'])->toBe('ai-visibility');
    }
});

it('gives every ability a label, a description and an input schema', function (): void {
    foreach ($this->abilities as $name => $ability) {
        expect($ability)->toHaveKeys(['label', 'description', 'input_schema', 'execute_callback', 'permission_callback'], $name)
            ->and($ability['label'])->not->toBe('')
            ->and($ability['description'])->not->toBe('')
            ->and($ability['input_schema']['type'])->toBe('object');
    }
});

describe('permissions', function (): void {
    it('guards every ability with a capability check', function (): void {
        foreach ($this->abilities as $ability) {
            Functions\expect('current_user_can')->once()->andReturn(true);

            expect(($ability['permission_callback'])())->toBeTrue();
        }
    });

    it('requires manage_options to regenerate, and only read to look', function (): void {
        $required = [];
        Functions\when('current_user_can')->alias(static function (string $capability) use (&$required): bool {
            $required[] = $capability;

            return true;
        });

        foreach ($this->abilities as $ability) {
            ($ability['permission_callback'])();
        }

        expect($required)->toBe(['read', 'manage_options', 'read']);
    });

    it('refuses when the capability is absent', function (): void {
        Functions\when('current_user_can')->justReturn(false);

        foreach ($this->abilities as $ability) {
            expect(($ability['permission_callback'])())->toBeFalse();
        }
    });
});

describe('exposure to MCP', function (): void {
    it('marks every ability public so an MCP client can discover it', function (): void {
        foreach ($this->abilities as $ability) {
            expect($ability['meta']['mcp']['public'])->toBeTrue();
        }
    });
});

describe('get-site-summary', function (): void {
    it('reports the site identity and the counts of tracked types', function (): void {
        $this->setSettings(['post_types' => ['post', 'page']]);
        Functions\when('wp_count_posts')->alias(
            static fn (string $type): object => (object) ['publish' => $type === 'post' ? '12' : 3],
        );

        $summary = ($this->abilities['ai-visibility/get-site-summary']['execute_callback'])();

        expect($summary['name'])->toBe('Example Site')
            ->and($summary['url'])->toBe('https://example.test/')
            ->and($summary['language'])->toBe('en-US')
            ->and($summary['post_counts'])->toBe(['post' => 12, 'page' => 3])
            ->and($summary['llms_txt'])->toBe('https://example.test/llms.txt')
            ->and($summary['ai_txt'])->toBe('https://example.test/.well-known/ai.txt');
    });

    it('survives a post type wp_count_posts knows nothing about', function (): void {
        $this->setSettings(['post_types' => ['ghost']]);
        Functions\when('wp_count_posts')->justReturn(null);

        $summary = ($this->abilities['ai-visibility/get-site-summary']['execute_callback'])();

        expect($summary['post_counts'])->toBe(['ghost' => 0]);
    });
});
