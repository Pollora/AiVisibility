<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Generator;

/**
 * Converts HTML content to clean Markdown.
 * Handles WordPress block markup without external dependencies.
 */
final class MarkdownConverter
{
    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Strip WordPress block comments
        $html = $this->replace('/<!--\s*\/?wp:[^>]*-->/', '', $html);

        // Remove unwanted elements
        $html = $this->replace('/<(script|style|nav|footer|aside|form|iframe|noscript)[^>]*>.*?<\/\1>/si', '', $html);

        // Process in order: block-level elements first, then inline
        $md = $this->convertBlockElements($html);
        $md = $this->convertInlineElements($md);
        $md = $this->cleanup($md);

        return trim($md);
    }

    public function convertPost(\WP_Post $post): string
    {
        // Password-protected content is not ours to publish in another format.
        if (post_password_required($post)) {
            return '';
        }

        $content = apply_filters('the_content', $post->post_content);

        return $this->convert(is_string($content) ? $content : '');
    }

    /**
     * preg_replace() that never loses the subject.
     *
     * These patterns are lazy-quantified and run over whole rendered pages;
     * PCRE gives up with a backtrack-limit error on pathological markup and
     * returns null. Returning the untouched subject degrades one conversion
     * step instead of blanking the entire document.
     */
    private function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /**
     * @param  callable(array<string>): string  $callback
     */
    private function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        return preg_replace_callback($pattern, $callback, $subject) ?? $subject;
    }

    private function convertBlockElements(string $html): string
    {
        // Headings
        $html = $this->replaceCallback(
            '/<h([1-6])[^>]*>(.*?)<\/h\1>/si',
            static fn (array $m): string => "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . strip_tags($m[2]) . "\n\n",
            $html,
        );

        // Blockquotes
        $html = $this->replaceCallback(
            '/<blockquote[^>]*>(.*?)<\/blockquote>/si',
            fn (array $m): string => "\n\n" . $this->prefixLines('> ', strip_tags(trim($m[1]))) . "\n\n",
            $html,
        );

        // Tables
        $html = $this->replaceCallback(
            '/<table[^>]*>(.*?)<\/table>/si',
            fn (array $m): string => "\n\n" . $this->convertTable($m[1]) . "\n\n",
            $html,
        );

        // Unordered lists
        $html = $this->replaceCallback(
            '/<ul[^>]*>(.*?)<\/ul>/si',
            fn (array $m): string => "\n\n" . $this->convertList($m[1], '-') . "\n",
            $html,
        );

        // Ordered lists
        $html = $this->replaceCallback(
            '/<ol[^>]*>(.*?)<\/ol>/si',
            fn (array $m): string => "\n\n" . $this->convertList($m[1], '1.') . "\n",
            $html,
        );

        // Figures with captions
        $html = $this->replaceCallback(
            '/<figure[^>]*>.*?<img[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>.*?(?:<figcaption[^>]*>(.*?)<\/figcaption>)?.*?<\/figure>/si',
            static fn (array $m): string => "\n\n![" . ($m[2] ?: strip_tags($m[3] ?? '')) . '](' . $m[1] . ")\n\n",
            $html,
        );

        // Figures with alt after src
        $html = $this->replaceCallback(
            '/<figure[^>]*>.*?<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*>.*?<\/figure>/si',
            static fn (array $m): string => "\n\n![" . $m[1] . '](' . $m[2] . ")\n\n",
            $html,
        );

        // Paragraphs
        $html = $this->replace('/<p[^>]*>(.*?)<\/p>/si', "\n\n$1\n\n", $html);

        // Horizontal rules
        $html = $this->replace('/<hr[^>]*\/?>/i', "\n\n---\n\n", $html);

        // Line breaks
        $html = $this->replace('/<br[^>]*\/?>/i', "  \n", $html);

        return $html;
    }

    private function convertInlineElements(string $html): string
    {
        // Fenced code blocks first: the inline <code> rule below would otherwise
        // consume the inner element and leave a bare <pre> behind.
        $html = $this->replaceCallback(
            '/<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>/si',
            static fn (array $m): string => self::fence($m[1]),
            $html,
        );

        // <pre> without a <code> child — still preformatted, still a block.
        $html = $this->replaceCallback(
            '/<pre[^>]*>(.*?)<\/pre>/si',
            static fn (array $m): string => self::fence($m[1]),
            $html,
        );

        // Bold
        $html = $this->replace('/<(strong|b)[^>]*>(.*?)<\/\1>/si', '**$2**', $html);

        // Italic
        $html = $this->replace('/<(em|i)[^>]*>(.*?)<\/\1>/si', '*$2*', $html);

        // Inline code
        $html = $this->replace('/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html);

        // Links
        $html = $this->replaceCallback(
            '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
            static fn (array $m): string => '[' . strip_tags($m[2]) . '](' . $m[1] . ')',
            $html,
        );

        // Standalone images (not in figures)
        $html = $this->replaceCallback(
            '/<img[^>]*src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/si',
            static fn (array $m): string => '![' . $m[2] . '](' . $m[1] . ')',
            $html,
        );

        $html = $this->replaceCallback(
            '/<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*>/si',
            static fn (array $m): string => '![' . $m[1] . '](' . $m[2] . ')',
            $html,
        );

        // Strip remaining tags
        $html = strip_tags($html);

        // Decode entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $html;
    }

    private function convertList(string $html, string $marker): string
    {
        $items = [];
        preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $matches);

        foreach ($matches[1] as $item) {
            $text = trim(strip_tags($item));
            if ($text !== '') {
                $items[] = $marker . ' ' . $text;
            }
        }

        return implode("\n", $items);
    }

    private function convertTable(string $html): string
    {
        $rows = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $trMatches);

        foreach ($trMatches[1] as $index => $tr) {
            $cells = [];
            preg_match_all('/<(th|td)[^>]*>(.*?)<\/\1>/si', $tr, $cellMatches);

            foreach ($cellMatches[2] as $cell) {
                $cells[] = trim(strip_tags($cell));
            }

            if ($cells === []) {
                continue;
            }

            $rows[] = '| ' . implode(' | ', $cells) . ' |';

            // Add separator after header row
            if ($index === 0) {
                $rows[] = '| ' . implode(' | ', array_fill(0, count($cells), '---')) . ' |';
            }
        }

        return implode("\n", $rows);
    }

    /**
     * Wrap preformatted text in a Markdown fence.
     */
    private static function fence(string $code): string
    {
        $text = html_entity_decode(strip_tags($code), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return "\n\n```\n" . trim($text, "\n") . "\n```\n\n";
    }

    private function prefixLines(string $prefix, string $text): string
    {
        return implode(
            "\n",
            array_map(
                static fn (string $line): string => $prefix . $line,
                explode("\n", $text),
            ),
        );
    }

    private function cleanup(string $md): string
    {
        // Collapse multiple blank lines into two
        $md = $this->replace('/\n{3,}/', "\n\n", $md);

        // Trim trailing whitespace per line — except the two spaces that encode
        // a Markdown hard line break, which is what <br> was converted into.
        return implode(
            "\n",
            array_map(
                static function (string $line): string {
                    $trimmed = rtrim($line);

                    return $trimmed !== '' && str_ends_with($line, '  ') ? $trimmed . '  ' : $trimmed;
                },
                explode("\n", $md),
            ),
        );
    }
}
