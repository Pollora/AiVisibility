<?php

declare(strict_types=1);

use Brain\Monkey\Filters as MonkeyFilters;
use Pollora\AiVisibility\Support\Filters;

describe('Filters::string', function (): void {
    it('returns what the filter returned when it is a string', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_llms_txt')->once()->andReturn('replaced');

        expect(Filters::string('ai_visibility_llms_txt', 'original'))->toBe('replaced');
    });

    it('falls back to the unfiltered value when a callback misbehaves', function (mixed $returned): void {
        MonkeyFilters\expectApplied('ai_visibility_llms_txt')->once()->andReturn($returned);

        expect(Filters::string('ai_visibility_llms_txt', 'original'))->toBe('original');
    })->with([
        'null from a bare return' => [null],
        'array from a copy-pasted hook' => [['oops']],
        'integer' => [42],
        'object' => [new stdClass()],
        'boolean' => [false],
    ]);

    it('passes extra arguments through to the filter', function (): void {
        $post = $this->post(7, 'Title');

        MonkeyFilters\expectApplied('ai_visibility_markdown')
            ->once()
            ->with('body', $post)
            ->andReturn('body');

        Filters::string('ai_visibility_markdown', 'body', $post);
    });
});

describe('Filters::metaLines', function (): void {
    it('keeps string and numeric values, keyed by name', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_markdown_meta')->once()->andReturn([
            'Location' => 'Angres',
            'Attendees' => 42,
            'Price' => 12.5,
        ]);

        expect(Filters::metaLines('ai_visibility_markdown_meta'))->toBe([
            'Location' => 'Angres',
            'Attendees' => '42',
            'Price' => '12.5',
        ]);
    });

    it('drops entries that cannot become a metadata line', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_markdown_meta')->once()->andReturn([
            'Kept' => 'yes',
            'Empty' => '',
            'Null' => null,
            'Array' => ['a'],
            'Object' => new stdClass(),
            'Bool' => true,
            0 => 'numeric key',
            '' => 'empty key',
        ]);

        expect(Filters::metaLines('ai_visibility_markdown_meta'))->toBe(['Kept' => 'yes']);
    });

    it('returns an empty array when the filter returns a non-array', function (mixed $returned): void {
        MonkeyFilters\expectApplied('ai_visibility_markdown_meta')->once()->andReturn($returned);

        expect(Filters::metaLines('ai_visibility_markdown_meta'))->toBe([]);
    })->with([['a string'], [null], [42]]);
});

describe('Filters::array', function (): void {
    it('returns the filtered array', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_identity_json')->once()->andReturn(['a' => 1]);

        expect(Filters::array('ai_visibility_identity_json', ['b' => 2]))->toBe(['a' => 1]);
    });

    it('falls back to the unfiltered array when the callback returns a scalar', function (): void {
        MonkeyFilters\expectApplied('ai_visibility_identity_json')->once()->andReturn('not an array');

        expect(Filters::array('ai_visibility_identity_json', ['b' => 2]))->toBe(['b' => 2]);
    });
});
