<?php

declare(strict_types=1);

use Brain\Monkey\Filters as MonkeyFilters;
use Pollora\AiVisibility\Generator\AiTxtGenerator;
use Pollora\AiVisibility\Generator\IdentityJsonGenerator;

describe('ai.txt', function (): void {
    beforeEach(function (): void {
        $this->generator = new AiTxtGenerator();
    });

    it('declares the site name and URL', function (): void {
        $this->setSettings([]);

        expect($this->generator->generate())
            ->toHaveLine('Name: Example Site')
            ->toHaveLine('Site: https://example.test/');
    });

    it('prefers the configured description over the WordPress tagline', function (): void {
        $this->setSettings(['site_description' => 'A municipal opposition site']);

        expect($this->generator->generate())
            ->toHaveLine('Description: A municipal opposition site')
            ->notToHaveLine('Description: Just another example');
    });

    it('falls back to the tagline when no description is configured', function (): void {
        $this->setSettings(['site_description' => '   ']);

        expect($this->generator->generate())->toHaveLine('Description: Just another example');
    });

    it('flattens a multi-line description onto one line', function (): void {
        $this->setSettings(['site_description' => "First line\nSecond line"]);

        expect($this->generator->generate())->toHaveLine('Description: First line Second line');
    });

    it('derives the policies from the crawler lists', function (array $settings, array $expected): void {
        $this->setSettings($settings);

        $output = $this->generator->generate();

        foreach (['Policy: allow-ai-search', 'Policy: disallow-ai-training'] as $policy) {
            in_array($policy, $expected, true)
                ? expect($output)->toHaveLine($policy)
                : expect($output)->notToHaveLine($policy);
        }
    })->with([
        'allow only' => [
            ['crawlers_allow' => ['GPTBot'], 'crawlers_block' => []],
            ['Policy: allow-ai-search'],
        ],
        'block only' => [
            ['crawlers_allow' => [], 'crawlers_block' => ['CCBot']],
            ['Policy: disallow-ai-training'],
        ],
        'both' => [
            ['crawlers_allow' => ['GPTBot'], 'crawlers_block' => ['CCBot']],
            ['Policy: allow-ai-search', 'Policy: disallow-ai-training'],
        ],
        'neither' => [
            ['crawlers_allow' => [], 'crawlers_block' => []],
            [],
        ],
    ]);

    it('lists the discovery files', function (): void {
        $this->setSettings([]);

        expect($this->generator->generate())
            ->toHaveLine('LLMS-TXT: https://example.test/llms.txt')
            ->toHaveLine('LLMS-Full-TXT: https://example.test/llms-full.txt')
            ->toHaveLine('Identity: https://example.test/.well-known/identity.json')
            ->toHaveLine('Sitemap: https://example.test/sitemap.xml');
    });

    it('includes a contact only when one is configured', function (): void {
        $this->setSettings(['identity_email' => '']);
        expect($this->generator->generate())->not->toContain('Contact:');

        $this->setSettings(['identity_email' => 'hello@example.test']);
        expect($this->generator->generate())->toHaveLine('Contact: hello@example.test');
    });

    it('ends with a newline', function (): void {
        $this->setSettings([]);

        expect($this->generator->generate())->toEndWith("\n");
    });

    it('is filterable', function (): void {
        $this->setSettings([]);
        MonkeyFilters\expectApplied('ai_visibility_ai_txt')->once()->andReturn('overridden');

        expect($this->generator->generate())->toBe('overridden');
    });
});

describe('identity.json', function (): void {
    beforeEach(function (): void {
        $this->generator = new IdentityJsonGenerator();
    });

    it('produces valid JSON', function (): void {
        $this->setSettings([]);

        expect(json_decode($this->generator->generate(), true))->toBeArray()
            ->and(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('describes the site as a schema.org Organization', function (): void {
        $this->setSettings([]);

        $identity = json_decode($this->generator->generate(), true);

        expect($identity['@context'])->toBe('https://schema.org')
            ->and($identity['@type'])->toBe('Organization')
            ->and($identity['name'])->toBe('Example Site')
            ->and($identity['url'])->toBe('https://example.test/')
            ->and($identity['language'])->toBe('en-US');
    });

    it('omits the email key entirely when none is configured', function (): void {
        $this->setSettings(['identity_email' => '']);

        expect(json_decode($this->generator->generate(), true))->not->toHaveKey('email');
    });

    it('exposes social links as sameAs', function (): void {
        $this->setSettings(['identity_socials' => ['https://example.test/a', 'https://example.test/b']]);

        $identity = json_decode($this->generator->generate(), true);

        expect($identity['sameAs'])->toBe(['https://example.test/a', 'https://example.test/b']);
    });

    it('omits sameAs when there are no social links', function (): void {
        $this->setSettings(['identity_socials' => []]);

        expect(json_decode($this->generator->generate(), true))->not->toHaveKey('sameAs');
    });

    it('points at the llms files through additionalProperty', function (): void {
        $this->setSettings([]);

        $identity = json_decode($this->generator->generate(), true);
        $byName = array_column($identity['additionalProperty'], 'value', 'name');

        expect($byName['llms-txt'])->toBe('https://example.test/llms.txt')
            ->and($byName['llms-full-txt'])->toBe('https://example.test/llms-full.txt');
    });

    it('leaves slashes and accents unescaped for readability', function (): void {
        $this->setSettings(['site_description' => 'Café & crème']);

        expect($this->generator->generate())
            ->toContain('https://example.test/')
            ->not->toContain('https:\/\/')
            ->toContain('Café');
    });

    it('degrades to an empty object rather than emitting invalid JSON', function (): void {
        $this->setSettings([]);
        // A filter returning unencodable data (a resource) must not produce `false`.
        MonkeyFilters\expectApplied('ai_visibility_identity_json')
            ->once()
            ->andReturn(['stream' => fopen('php://memory', 'rb')]);

        expect($this->generator->generate())->toBe('{}');
    });
});
