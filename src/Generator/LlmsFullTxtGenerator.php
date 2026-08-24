<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Generator;

use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\Filters;

/**
 * Generates the llms-full.txt file with complete Markdown content of all pages.
 */
final class LlmsFullTxtGenerator
{
    private readonly SeoIntegration $seo;

    private readonly MarkdownConverter $converter;

    public function __construct(?SeoIntegration $seo = null, ?MarkdownConverter $converter = null)
    {
        $this->seo = $seo ?? new SeoIntegration();
        $this->converter = $converter ?? new MarkdownConverter();
    }

    public function generate(): string
    {
        $settings = Plugin::settings();
        $sections = [];

        // Header
        $sections[] = '# ' . get_bloginfo('name');
        $description = get_bloginfo('description');
        if ($description !== '') {
            $sections[] = '';
            $sections[] = '> ' . $description;
        }

        foreach ($settings['post_types'] as $postType) {
            if (!get_post_type_object($postType) instanceof \WP_Post_Type) {
                continue;
            }

            foreach (PostQuery::published($postType, $settings['posts_per_type']) as $postId) {
                if ($this->seo->isNoindex($postId)) {
                    continue;
                }

                $post = get_post($postId);
                if (!$post instanceof \WP_Post || post_password_required($post)) {
                    continue;
                }

                foreach ($this->renderPost($post) as $line) {
                    $sections[] = $line;
                }

                // Free memory for large sites
                clean_post_cache($postId);
            }
        }

        return Filters::string('ai_visibility_llms_full_txt', implode("\n", $sections));
    }

    /**
     * One post's section, or an empty list when it has no usable content.
     *
     * @return list<string>
     */
    private function renderPost(\WP_Post $post): array
    {
        $markdown = Filters::string(
            'ai_visibility_markdown_content',
            $this->converter->convertPost($post),
            $post,
        );

        if (trim($markdown) === '') {
            return [];
        }

        $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [
            '',
            '---',
            '',
            "## {$title}",
            '',
            'URL: ' . get_permalink($post),
        ];

        // Custom metadata from themes/plugins
        foreach (Filters::metaLines('ai_visibility_markdown_meta', $post) as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        $lines[] = '';

        // Before content hook
        $beforeContent = Filters::string('ai_visibility_markdown_before_content', '', $post);
        if ($beforeContent !== '') {
            $lines[] = $beforeContent;
            $lines[] = '';
        }

        $lines[] = $markdown;

        return $lines;
    }
}
