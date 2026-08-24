<?php

declare(strict_types=1);

use Pollora\AiVisibility\Plugin;

it('returns the defaults when nothing has been saved', function (): void {
    expect(Plugin::settings())->toBe(Plugin::defaultSettings());
});

it('keeps every default key when the stored row is partial', function (): void {
    $this->setSettings(['enable_markdown' => false]);

    $settings = Plugin::settings();

    expect($settings)->toHaveKeys(array_keys(Plugin::defaultSettings()))
        ->and($settings['enable_markdown'])->toBeFalse()
        ->and($settings['enable_robots'])->toBeTrue();
});

it('falls back to the defaults when the option row is not an array', function (): void {
    $this->setSettings([]);
    $this->setOption('ai_visibility_settings', 'corrupted-by-a-migration');

    expect(Plugin::settings())->toBe(Plugin::defaultSettings());
});

describe('type coercion', function (): void {
    it('coerces truthy and falsy stored values to booleans', function (mixed $stored, bool $expected): void {
        $this->setSettings(['enable_robots' => $stored]);

        expect(Plugin::settings()['enable_robots'])->toBe($expected);
    })->with([
        'checkbox on' => ['1', true],
        'checkbox off' => ['0', false],
        'empty string' => ['', false],
        'integer one' => [1, true],
        'integer zero' => [0, false],
        'null' => [null, false],
    ]);

    it('replaces a non-string description with the default', function (): void {
        $this->setSettings(['site_description' => ['unexpected', 'array']]);

        expect(Plugin::settings()['site_description'])->toBe('');
    });

    it('drops non-string entries from list settings', function (): void {
        $this->setSettings(['crawlers_block' => ['CCBot', 42, null, '', 'Bytespider', ['nested']]]);

        expect(Plugin::settings()['crawlers_block'])->toBe(['CCBot', 'Bytespider']);
    });

    it('returns a list, not a sparse array, after filtering', function (): void {
        $this->setSettings(['post_types' => [3 => 'post', 7 => 'page']]);

        expect(Plugin::settings()['post_types'])->toBe(['post', 'page'])
            ->and(array_is_list(Plugin::settings()['post_types']))->toBeTrue();
    });

    it('honours an explicitly emptied post type list', function (): void {
        $this->setSettings(['post_types' => []]);

        expect(Plugin::settings()['post_types'])->toBe([]);
    });

    it('restores the default list when the stored value is not an array', function (): void {
        $this->setSettings(['post_types' => 'post,page']);

        expect(Plugin::settings()['post_types'])->toBe(['post', 'page']);
    });
});

describe('posts_per_type bounds', function (): void {
    it('clamps the stored value into range', function (mixed $stored, int $expected): void {
        $this->setSettings(['posts_per_type' => $stored]);

        expect(Plugin::settings()['posts_per_type'])->toBe($expected);
    })->with([
        'below the floor' => [0, Plugin::MIN_POSTS_PER_TYPE],
        'negative' => [-25, Plugin::MIN_POSTS_PER_TYPE],
        'above the ceiling' => [10_000, Plugin::MAX_POSTS_PER_TYPE],
        'numeric string' => ['75', 75],
        'in range' => [12, 12],
        'not numeric' => ['many', 50],
        'float' => [12.9, 12],
    ]);
});

it('never lets a corrupt row reach a consumer as the wrong type', function (): void {
    $this->setSettings([
        'enable_llms_txt' => 'yes',
        'post_types' => null,
        'posts_per_type' => 'lots',
        'identity_socials' => 'https://example.com',
        'identity_email' => false,
    ]);

    $settings = Plugin::settings();

    expect($settings['enable_llms_txt'])->toBeBool()
        ->and($settings['post_types'])->toBeArray()
        ->and($settings['posts_per_type'])->toBeInt()
        ->and($settings['identity_socials'])->toBeArray()
        ->and($settings['identity_email'])->toBeString();
});
