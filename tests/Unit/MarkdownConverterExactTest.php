<?php

declare(strict_types=1);

use Pollora\AiVisibility\Generator\MarkdownConverter;

beforeEach(function (): void {
    $this->converter = new MarkdownConverter();
});

describe('exact conversion', function (): void {
    it('does not mistake a literal for blank input', function (): void {
        expect($this->converter->convert('PEST Mutator was here!'))->toBe('PEST Mutator was here!');
    });

    it('replaces a removed element with nothing at all', function (): void {
        expect($this->converter->convert('<p>Before</p><script>x()</script><p>After</p>'))
            ->toBe("Before\n\nAfter");
    });

    it('removes an element whatever the case of its tags', function (): void {
        expect($this->converter->convert("<p>a</p><NAV class=\"m\">menu\nmore</NAV><p>b</p>"))
            ->toBe("a\n\nb");
    });

    it('strips inline tags out of a heading', function (): void {
        expect($this->converter->convert('<h2>A <em>b</em> <a href="/x">c</a></h2>'))->toBe('## A b c');
    });

    it('keeps one blank line between consecutive headings', function (): void {
        expect($this->converter->convert('<h1>One</h1><h2>Two</h2>'))->toBe("# One\n\n## Two");
    });

    it('trims and prefixes a blockquote line by line', function (): void {
        expect($this->converter->convert("<p>a</p><blockquote>\n  First\nSecond <b>x</b>\n</blockquote><p>b</p>"))
            ->toBe("a\n\n> First\n> Second x\n\nb");
    });

    it('reads a blockquote body, not its opening tag', function (): void {
        expect($this->converter->convert('<blockquote class="q" data-x="1">Said</blockquote>'))->toBe('> Said');
    });

    it('keeps a table apart from the text around it', function (): void {
        $html = '<p>a</p><table><tr><th> H1 </th><th><b>H2</b></th></tr><tr><td>x</td><td>y</td></tr></table><p>b</p>';

        expect($this->converter->convert($html))
            ->toBe("a\n\n| H1 | H2 |\n| --- | --- |\n| x | y |\n\nb");
    });

    it('sizes the separator row on the header cells', function (): void {
        $html = '<table><tr><th>A</th><th>B</th><th>C</th></tr><tr><td>1</td><td>2</td><td>3</td></tr></table>';

        expect($this->converter->convert($html))->toBe("| A | B | C |\n| --- | --- | --- |\n| 1 | 2 | 3 |");
    });

    it('puts the separator after the first row that has cells, even when it is not the first row', function (): void {
        $html = '<table><tr></tr><tr><th>A</th></tr><tr><td>1</td></tr></table>';

        // The index is that of the <tr>, not of the emitted row: an empty first
        // row means no separator is ever written.
        expect($this->converter->convert($html))->toBe("| A |\n| 1 |");
    });

    it('does not read a cell tag of another kind as a cell', function (): void {
        expect($this->converter->convert('<table><tr><td>x</td></tr></table>'))->toBe("| x |\n| --- |");
    });

    it('keeps lists apart from the text around them', function (): void {
        expect($this->converter->convert('<p>a</p><ul><li>One <b>x</b></li><li>Two</li></ul><p>b</p>'))
            ->toBe("a\n\n- One x\n- Two\n\nb")
            ->and($this->converter->convert('<p>a</p><ol><li>One</li><li>Two</li></ol><p>b</p>'))
            ->toBe("a\n\n1. One\n1. Two\n\nb");
    });

    it('strips inline tags and whitespace from a list item', function (): void {
        expect($this->converter->convert("<ul><li>\n <em>Ital</em> and <a href=\"/x\">link</a>\n</li></ul>"))
            ->toBe('- Ital and link');
    });

    it('converts a figure whose alt comes first', function (): void {
        $html = '<figure><img alt="Alt A" src="a.png"><figcaption>Cap</figcaption></figure>';

        expect($this->converter->convert($html))->toBe('![Alt A](a.png)');
    });

    it('keeps the alt and the source of a figure in their own places', function (): void {
        expect($this->converter->convert('<p>a</p><figure><img src="s.png" alt="Alt S"></figure><p>b</p>'))
            ->toBe("a\n\n![Alt S](s.png)\n\nb")
            ->and($this->converter->convert('<p>a</p><figure><img alt="Alt T" src="t.png"></figure><p>b</p>'))
            ->toBe("a\n\n![Alt T](t.png)\n\nb");
    });

    it('strips markup from a caption used as alt text', function (): void {
        $html = '<figure><img src="a.png" alt=""><figcaption>A <b>bold</b> caption</figcaption></figure>';

        expect($this->converter->convert($html))->toBe('![A bold caption](a.png)');
    });

    it('survives a figure without caption and empty alt', function (): void {
        expect($this->converter->convert('<figure><img src="a.png" alt=""></figure>'))->toBe('![](a.png)');
    });

    it('keeps paragraphs, rules and breaks apart', function (): void {
        expect($this->converter->convert('<p>a</p><hr><p>b</p>'))->toBe("a\n\n---\n\nb")
            ->and($this->converter->convert('<p>a</p><hr class="x" /><p>b</p>'))->toBe("a\n\n---\n\nb")
            ->and($this->converter->convert('<p class="x">a<br/>b<br />c</p>'))->toBe("a  \nb  \nc");
    });

    it('fences a code block and decodes its entities, tags and surrounding newlines', function (): void {
        expect($this->converter->convert("a<pre><code>\n\nif (\$a &lt; 1 &amp;&amp; \$b) {}\n<span>x</span>\n\n</code></pre>b"))
            ->toBe("a\n\n```\nif (\$a < 1 && \$b) {}\nx\n```\n\nb");
    });

    it('fences a bare pre as well', function (): void {
        expect($this->converter->convert("<pre class=\"x\">\nline &quot;one&quot;\n  two\n</pre>"))
            ->toBe("```\nline \"one\"\n  two\n```");
    });

    it('allows whitespace between pre and code', function (): void {
        expect($this->converter->convert("<pre>\n  <code>x</code>\n</pre>"))->toBe("```\nx\n```");
    });

    it('does not turn a fenced block into inline code', function (): void {
        expect($this->converter->convert('<pre><code>a</code></pre>'))->not->toContain('`a`');
    });

    it('converts a link and strips tags from its text', function (): void {
        expect($this->converter->convert('<p><a class="x" href=\'/one\' rel="n">A <b>bold</b> link</a></p>'))
            ->toBe('[A **bold** link](/one)');
    });

    it('converts standalone images in both attribute orders, with the right parts in the right places', function (): void {
        expect($this->converter->convert('<p><img src="s.png" alt="Alt S"> <img alt="Alt T" src="t.png"></p>'))
            ->toBe('![Alt S](s.png) ![Alt T](t.png)');
    });

    it('decodes quotes and HTML5 entities', function (): void {
        expect($this->converter->convert('<p>&quot;a&quot; &apos;b&apos; &lt;c&gt; &hellip;</p>'))
            ->toBe('"a" \'b\' <c> …');
    });

    it('trims nothing but the line ends, and keeps indentation inside text', function (): void {
        expect($this->converter->convert("<p>  indented</p>"))->toBe('indented');
    });

    it('keeps a hard line break as exactly two trailing spaces', function (): void {
        $markdown = $this->converter->convert("<p>a<br>b</p><p>c<br>   <br>d</p>");

        expect($markdown)->toBe("a  \nb\n\nc  \n\nd");
    });

    it('empties a whitespace-only line', function (): void {
        // Blank lines are collapsed before whitespace-only lines are emptied,
        // so the whitespace line keeps two extra blank lines around it.
        expect($this->converter->convert("<ul><li>a</li></ul>\n   \n<ul><li>b</li></ul>"))->toBe("- a\n\n\n\n- b");
    });

    it('trims tabs and trailing spaces on a line that is not a hard break', function (): void {
        expect($this->converter->convert("<p>one \t\ntwo   x</p>"))->toBe("one\ntwo   x");
    });

    it('collapses exactly three newlines to two and leaves two alone', function (): void {
        expect($this->converter->convert("<p>a</p>\n\n\n\n<p>b</p>"))->toBe("a\n\nb");
    });

    it('removes a block comment with attributes and spacing', function (): void {
        expect($this->converter->convert('<!--  wp:heading {"level":2} --><h2>T</h2><!--  /wp:heading  -->'))->toBe('## T');
    });

    it('keeps an ordinary HTML comment out of the output', function (): void {
        expect($this->converter->convert('<p>a</p><!-- keep? -->'))->toBe('a');
    });

    it('leaves a line made only of two spaces empty', function (): void {
        expect($this->converter->convert("a\n  \nb"))->toBe("a\n\nb");
    });

    it('separates every block from the loose text around it', function (string $html, string $expected): void {
        expect($this->converter->convert($html))->toBe($expected);
    })->with([
        'heading' => ['a<h2>b</h2>c', "a\n\n## b\n\nc"],
        'blockquote' => ['a<blockquote>b</blockquote>c', "a\n\n> b\n\nc"],
        'table' => ['a<table><tr><td>b</td></tr></table>c', "a\n\n| b |\n| --- |\n\nc"],
        'unordered list' => ['a<ul><li>b</li></ul>c', "a\n\n- b\n\nc"],
        'ordered list' => ['a<ol><li>b</li></ol>c', "a\n\n1. b\n\nc"],
        'two lists' => ['<ul><li>a</li></ul><ol><li>b</li></ol>', "- a\n\n1. b"],
    ]);
});
