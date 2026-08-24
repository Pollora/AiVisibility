<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\AiVisibility\Generator\MarkdownConverter;

beforeEach(function (): void {
    $this->converter = new MarkdownConverter();
});

describe('block elements', function (): void {
    it('converts every heading level to the matching number of hashes', function (int $level): void {
        $markdown = $this->converter->convert("<h{$level}>Heading</h{$level}>");

        expect($markdown)->toBe(str_repeat('#', $level) . ' Heading');
    })->with([1, 2, 3, 4, 5, 6]);

    it('keeps heading attributes out of the output', function (): void {
        $markdown = $this->converter->convert('<h2 class="wp-block-heading" id="x">Title</h2>');

        expect($markdown)->toBe('## Title');
    });

    it('converts an unordered list', function (): void {
        $markdown = $this->converter->convert('<ul><li>First</li><li>Second</li></ul>');

        expect($markdown)->toHaveLine('- First')->toHaveLine('- Second');
    });

    it('converts an ordered list', function (): void {
        $markdown = $this->converter->convert('<ol><li>First</li><li>Second</li></ol>');

        expect($markdown)->toHaveLine('1. First')->toHaveLine('1. Second');
    });

    it('drops empty list items', function (): void {
        $markdown = $this->converter->convert('<ul><li>Kept</li><li>   </li><li></li></ul>');

        expect(explode("\n", $markdown))->toBe(['- Kept']);
    });

    it('converts a table with a header separator row', function (): void {
        $html = '<table><tr><th>Year</th><th>Count</th></tr><tr><td>2024</td><td>12</td></tr></table>';

        expect($this->converter->convert($html))
            ->toHaveLine('| Year | Count |')
            ->toHaveLine('| --- | --- |')
            ->toHaveLine('| 2024 | 12 |');
    });

    it('emits one separator row regardless of the number of body rows', function (): void {
        $html = '<table><tr><th>A</th></tr><tr><td>1</td></tr><tr><td>2</td></tr><tr><td>3</td></tr></table>';

        $separators = array_filter(
            explode("\n", $this->converter->convert($html)),
            static fn (string $line): bool => $line === '| --- |',
        );

        expect($separators)->toHaveCount(1);
    });

    it('prefixes every line of a blockquote', function (): void {
        $markdown = $this->converter->convert("<blockquote><p>One</p>\n<p>Two</p></blockquote>");

        foreach (array_filter(explode("\n", $markdown)) as $line) {
            expect($line)->toStartWith('> ');
        }
    });

    it('converts horizontal rules and line breaks', function (): void {
        expect($this->converter->convert('<p>a</p><hr /><p>b</p>'))->toHaveLine('---');
        expect($this->converter->convert('<p>a<br>b</p>'))->toContain("a  \nb");
    });
});

describe('inline elements', function (): void {
    it('converts emphasis, strong and code', function (string $html, string $expected): void {
        expect($this->converter->convert("<p>{$html}</p>"))->toBe($expected);
    })->with([
        'strong' => ['<strong>bold</strong>', '**bold**'],
        'b' => ['<b>bold</b>', '**bold**'],
        'em' => ['<em>italic</em>', '*italic*'],
        'i' => ['<i>italic</i>', '*italic*'],
        'code' => ['<code>$x = 1;</code>', '`$x = 1;`'],
    ]);

    it('converts links to Markdown', function (): void {
        $markdown = $this->converter->convert('<p>See <a href="https://example.test/page">the page</a>.</p>');

        expect($markdown)->toBe('See [the page](https://example.test/page).');
    });

    it('converts an image regardless of attribute order', function (string $html): void {
        expect($this->converter->convert($html))->toBe('![A cat](cat.jpg)');
    })->with([
        'src first' => ['<img src="cat.jpg" alt="A cat">'],
        'alt first' => ['<img alt="A cat" src="cat.jpg">'],
    ]);

    it('converts a figure with a caption', function (): void {
        $html = '<figure class="wp-block-image"><img src="a.png" alt="Alt text"><figcaption>Caption</figcaption></figure>';

        expect($this->converter->convert($html))->toContain('![Alt text](a.png)');
    });

    it('falls back to the caption when the alt attribute is empty', function (): void {
        $html = '<figure><img src="a.png" alt=""><figcaption>A caption</figcaption></figure>';

        expect($this->converter->convert($html))->toContain('![A caption](a.png)');
    });

    it('converts a fenced code block', function (): void {
        $markdown = $this->converter->convert('<pre><code>echo 1;</code></pre>');

        expect($markdown)->toHaveLine('```')->toHaveLine('echo 1;');
    });
});

describe('sanitising', function (): void {
    it('strips WordPress block comments', function (): void {
        $html = '<!-- wp:paragraph --><p>Visible</p><!-- /wp:paragraph -->';

        expect($this->converter->convert($html))->toBe('Visible');
    });

    it('removes elements that carry no reading content', function (string $tag): void {
        $html = "<p>Before</p><{$tag}>hidden payload</{$tag}><p>After</p>";

        expect($this->converter->convert($html))
            ->toContain('Before')
            ->toContain('After')
            ->not->toContain('hidden payload');
    })->with(['script', 'style', 'nav', 'footer', 'aside', 'form', 'iframe', 'noscript']);

    it('strips tags it has no rule for', function (): void {
        expect($this->converter->convert('<p>Text with <span class="x">a span</span>.</p>'))
            ->toBe('Text with a span.');
    });

    it('decodes HTML entities', function (): void {
        expect($this->converter->convert('<p>Caf&eacute; &amp; cr&egrave;me &mdash; 5&nbsp;&euro;</p>'))
            ->toContain('Café')
            ->toContain('&')
            ->not->toContain('&amp;')
            ->not->toContain('&eacute;');
    });

    it('collapses runs of blank lines to at most one', function (): void {
        $markdown = $this->converter->convert('<h2>A</h2><p></p><p></p><p></p><h2>B</h2>');

        expect($markdown)->not->toMatch('/\n{3,}/');
    });

    it('leaves no trailing whitespace except a hard line break', function (): void {
        $markdown = $this->converter->convert('<ul><li>Item   </li></ul><p>Text&nbsp;&nbsp;&nbsp;</p>');

        foreach (explode("\n", $markdown) as $line) {
            expect($line)->toBe(rtrim($line));
        }
    });

    it('keeps the two trailing spaces that encode a hard line break', function (): void {
        expect($this->converter->convert('<p>a<br>b</p>'))->toBe("a  \nb");
    });

    it('returns an empty string for blank input', function (string $html): void {
        expect($this->converter->convert($html))->toBe('');
    })->with(['', '   ', "\n\n", "\t"]);
});

describe('resilience', function (): void {
    it('does not blank the document when a pattern hits the backtrack limit', function (): void {
        // A deeply nested structure that makes the lazy quantifiers backtrack.
        $html = '<p>' . str_repeat('<em>a</em> ', 20_000) . '</p>';

        $previousLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100');

        try {
            $markdown = $this->converter->convert($html);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        expect($markdown)->not->toBe('');
    });

    it('survives unbalanced markup without throwing', function (string $html): void {
        expect($this->converter->convert($html))->toBeString();
    })->with([
        'unclosed paragraph' => ['<p>Dangling'],
        'unclosed list' => ['<ul><li>One'],
        'stray closer' => ['Text</div></p>'],
        'nested tables' => ['<table><tr><td><table><tr><td>x</td></tr></table></td></tr></table>'],
        'attribute with angle bracket' => ['<a href="x" title="a > b">link</a>'],
    ]);
});

describe('convertPost', function (): void {
    it('runs the content through the_content filter', function (): void {
        Functions\when('post_password_required')->justReturn(false);
        Filters\expectApplied('the_content')->once()->andReturn('<p>Filtered</p>');

        $post = $this->post(1, 'Title', '<p>Raw</p>');

        expect($this->converter->convertPost($post))->toBe('Filtered');
    });

    it('returns nothing for a password-protected post', function (): void {
        Functions\when('post_password_required')->justReturn(true);

        $post = $this->post(1, 'Secret', '<p>Confidential</p>', ['post_password' => 'hunter2']);

        expect($this->converter->convertPost($post))->toBe('');
    });

    it('tolerates a filter that returns a non-string', function (): void {
        Functions\when('post_password_required')->justReturn(false);
        Filters\expectApplied('the_content')->once()->andReturn(null);

        expect($this->converter->convertPost($this->post(1)))->toBe('');
    });
});
