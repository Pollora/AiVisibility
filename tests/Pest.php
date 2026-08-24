<?php

declare(strict_types=1);

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
