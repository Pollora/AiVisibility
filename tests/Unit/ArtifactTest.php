<?php

declare(strict_types=1);

use Pollora\AiVisibility\Artifact;

it('round-trips every case through its slug', function (Artifact $artifact): void {
    expect(Artifact::fromSlug($artifact->slug()))->toBe($artifact);
})->with(Artifact::cases());

it('rejects an unknown slug', function (string $slug): void {
    expect(Artifact::fromSlug($slug))->toBeNull();
})->with(['', 'all', 'llms.txt', 'LLMS-TXT', '../../etc/passwd', 'llms-txt ']);

it('gives every case a distinct slug and filename', function (): void {
    expect(Artifact::slugs())->toHaveCount(count(Artifact::cases()))
        ->and(array_unique(Artifact::slugs()))->toHaveCount(count(Artifact::cases()));
});

it('exposes the filenames the specifications require', function (): void {
    expect(array_map(static fn (Artifact $a): string => $a->value, Artifact::cases()))
        ->toBe(['llms.txt', 'llms-full.txt', 'ai.txt', 'identity.json']);
});

describe('serving metadata', function (): void {
    it('serves identity.json as JSON and everything else as plain text', function (): void {
        expect(Artifact::IdentityJson->contentType())->toStartWith('application/json')
            ->and(Artifact::LlmsTxt->contentType())->toStartWith('text/plain')
            ->and(Artifact::LlmsFullTxt->contentType())->toStartWith('text/plain')
            ->and(Artifact::AiTxt->contentType())->toStartWith('text/plain');
    });

    it('declares a charset on every content type', function (Artifact $artifact): void {
        expect($artifact->contentType())->toContain('charset=utf-8');
    })->with(Artifact::cases());

    it('puts the llms files at the root and the discovery files under .well-known', function (): void {
        expect(Artifact::LlmsTxt->url())->toBe('https://example.test/llms.txt')
            ->and(Artifact::LlmsFullTxt->url())->toBe('https://example.test/llms-full.txt')
            ->and(Artifact::AiTxt->url())->toBe('https://example.test/.well-known/ai.txt')
            ->and(Artifact::IdentityJson->url())->toBe('https://example.test/.well-known/identity.json');
    });

    it('caches content-derived files for less time than site-identity files', function (): void {
        expect(Artifact::LlmsTxt->maxAge())->toBeLessThan(Artifact::AiTxt->maxAge())
            ->and(Artifact::LlmsFullTxt->maxAge())->toBeLessThan(Artifact::IdentityJson->maxAge());
    });

    it('always gives a positive max-age', function (Artifact $artifact): void {
        expect($artifact->maxAge())->toBeGreaterThan(0);
    })->with(Artifact::cases());
});
