<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Generator;

use Pollora\AiVisibility\Endpoint\MarkdownEndpoint;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\Filters;

/**
 * Generates the llms.txt index file following the llmstxt.org specification.
 */
final class LlmsTxtGenerator
{
    private readonly SeoIntegration $seo;

    public function __construct(?SeoIntegration $seo = null)
    {
        $this->seo = $seo ?? new SeoIntegration();
    }

    public function generate(): string
    {
        $settings = Plugin::settings();
        $lines = [];

        // H1: Site name (required by spec)
        $lines[] = '# ' . get_bloginfo('name');
        $lines[] = '';

        // Blockquote: WordPress tagline
        $tagline = get_bloginfo('description');
        if ($tagline !== '') {
            $lines[] = '> ' . $tagline;
            $lines[] = '';
        }

        // Site description for AI (free-form introduction)
        $siteDescription = trim($settings['site_description']);
        if ($siteDescription !== '') {
            $lines[] = $siteDescription;
            $lines[] = '';
        }

        // Complementary links by post type
        foreach ($settings['post_types'] as $postType) {
            $typeObject = get_post_type_object($postType);
            if (!$typeObject instanceof \WP_Post_Type) {
                continue;
            }

            $posts = PostQuery::published($postType, $settings['posts_per_type']);
            if ($posts === []) {
                continue;
            }

            $lines[] = '## ' . self::sectionLabel($typeObject, $postType);
            $lines[] = '';

            foreach ($posts as $postId) {
                if ($this->seo->isNoindex($postId)) {
                    continue;
                }

                $post = get_post($postId);
                if (!$post instanceof \WP_Post || post_password_required($post)) {
                    continue;
                }

                $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = MarkdownEndpoint::getMdUrl($post);
                $desc = $this->seo->getDescription($postId);

                $line = "- [{$title}]({$url})";
                if ($desc !== '') {
                    $line .= ": {$desc}";
                }

                $lines[] = $line;
            }

            $lines[] = '';
        }

        return Filters::string('ai_visibility_llms_txt', implode("\n", $lines));
    }

    /**
     * Human-readable heading for a post type section.
     *
     * `labels` is a plain stdClass assembled by core and by every plugin that
     * registers a type, so its contents are not guaranteed. Falls back to the
     * post type key, which always exists.
     */
    private static function sectionLabel(\WP_Post_Type $typeObject, string $postType): string
    {
        foreach ([$typeObject->labels->name ?? null, $typeObject->label] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return $postType;
    }
}
