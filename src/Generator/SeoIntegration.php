<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Generator;

/**
 * Multi-plugin SEO integration.
 * Detects TSF, Yoast, Rank Math, SEOPress and provides unified API.
 */
final class SeoIntegration
{
    public function getDescription(int $postId): string
    {
        // The SEO Framework. Its facade moved between v4 and v5, so the method
        // is probed rather than assumed.
        if (function_exists('the_seo_framework')) {
            $tsf = the_seo_framework();

            if (is_object($tsf) && method_exists($tsf, 'get_description')) {
                $desc = $tsf->get_description(['id' => $postId]);

                if (is_string($desc) && $desc !== '') {
                    return $desc;
                }
            }
        }

        // Yoast SEO
        $yoast = get_post_meta($postId, '_yoast_wpseo_metadesc', true);
        if (is_string($yoast) && $yoast !== '') {
            return $yoast;
        }

        // Rank Math
        $rankmath = get_post_meta($postId, 'rank_math_description', true);
        if (is_string($rankmath) && $rankmath !== '') {
            return $rankmath;
        }

        // SEOPress
        $seopress = get_post_meta($postId, '_seopress_titles_desc', true);
        if (is_string($seopress) && $seopress !== '') {
            return $seopress;
        }

        // Fallback: excerpt or trimmed content
        $post = get_post($postId);
        if (!$post) {
            return '';
        }

        if ($post->post_excerpt !== '') {
            return $post->post_excerpt;
        }

        return wp_trim_words(wp_strip_all_tags($post->post_content), 30, '…');
    }

    public function isNoindex(int $postId): bool
    {
        // The SEO Framework (check post meta directly — API varies across versions)
        $tsfNoindex = get_post_meta($postId, '_genesis_noindex', true);
        if ($tsfNoindex === '1') {
            return true;
        }

        // Yoast SEO
        $yoast = get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true);
        if ($yoast === '1') {
            return true;
        }

        // Rank Math
        $rankmath = get_post_meta($postId, 'rank_math_robots', true);
        if (is_array($rankmath) && in_array('noindex', $rankmath, true)) {
            return true;
        }
        if (is_string($rankmath) && str_contains($rankmath, 'noindex')) {
            return true;
        }

        // SEOPress
        $seopress = get_post_meta($postId, '_seopress_robots_index', true);
        if ($seopress === 'yes') {
            return true;
        }

        return false;
    }
}
