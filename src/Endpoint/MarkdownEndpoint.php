<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Endpoint;

use Pollora\AiVisibility\Generator\MarkdownConverter;
use Pollora\AiVisibility\Generator\SeoIntegration;
use Pollora\AiVisibility\Support\Filters;

/**
 * Serves Markdown versions of posts/pages.
 *
 * Supports two access patterns:
 * - URL suffix: /my-post.md (llms.txt spec convention)
 * - Query param: /my-post/?format=md (fallback)
 *
 * Cached in transients per post with 24h TTL.
 */
final class MarkdownEndpoint
{
    private const CACHE_TTL = DAY_IN_SECONDS;

    private const CACHE_PREFIX = 'ai_vis_md_';

    public function registerQueryVars(): void
    {
        // Advertise Markdown alternate on singular pages
        add_action('template_redirect', [$this, 'sendLinkHeader'], 1);
        add_action('wp_head', [$this, 'renderLinkTag'], 5);
    }

    public function registerRewriteRules(): void
    {
        add_filter('query_vars', [$this, 'registerQueryVar']);

        // Intercept .md URLs early in the request lifecycle, before rewrite rules
        // resolve. This avoids conflicts with CPT rewrite rules that would otherwise
        // capture the path (e.g. angres/associations/slug.md matched by association rule).
        add_action('parse_request', [$this, 'interceptMdRequest'], 1);
    }

    /**
     * @param  array<int, string>  $vars
     * @return array<int, string>
     */
    public function registerQueryVar(array $vars): array
    {
        $vars[] = 'format';

        return $vars;
    }

    /**
     * Early interception: if the request path ends with .md, strip it and
     * resolve the post directly — bypassing WordPress rewrite rule conflicts.
     */
    public function interceptMdRequest(\WP $wp): void
    {
        $requestPath = $wp->request;

        // /index.md → front page
        if ($requestPath === 'index.md') {
            $post = self::frontPage();

            if ($post instanceof \WP_Post) {
                $this->servePost($post);
            }

            return;
        }

        if (!str_ends_with($requestPath, '.md')) {
            return;
        }

        $path = substr($requestPath, 0, -3); // strip .md
        $this->handleMdPath($path);
    }

    /**
     * Route 2: the ?format=md query parameter.
     */
    public function handleRequest(): void
    {
        if (get_query_var('format') !== 'md') {
            return;
        }

        $post = self::requestedPost();

        if (!$post instanceof \WP_Post) {
            status_header(404);
            exit;
        }

        $this->servePost($post);
    }

    /**
     * Which post the current ?format=md request is asking for, if any.
     *
     * Kept free of output and exits so the routing decision can be tested on
     * its own; handleRequest() owns the response.
     */
    public static function requestedPost(): ?\WP_Post
    {
        $post = get_queried_object();

        if ($post instanceof \WP_Post) {
            return $post;
        }

        // The home request needs its own resolution. WP_Query only substitutes
        // page_on_front when the query carries nothing beyond preview, page,
        // paged and cpage — `format` is one more, so `/?format=md` falls through
        // to the blog index and the queried object is never the front page.
        //
        // Deliberately not a general fallback: on an archive the queried object
        // is a term, and serving the front page there would answer a category
        // URL with somebody else's document.
        if (is_front_page() || is_home()) {
            return self::frontPage();
        }

        return null;
    }

    /**
     * The post backing a static front page, if there is one.
     */
    private static function frontPage(): ?\WP_Post
    {
        $frontPageId = get_option('page_on_front');

        if (!is_numeric($frontPageId) || (int) $frontPageId <= 0) {
            return null;
        }

        return get_post((int) $frontPageId);
    }

    private function handleMdPath(string $path): void
    {
        // Remove trailing slash if present
        $path = rtrim($path, '/');

        // Try to find the post by slug
        $post = $this->findPostByPath($path);

        if (!$post) {
            status_header(404);
            exit;
        }

        $this->servePost($post);
    }

    private function findPostByPath(string $path): ?\WP_Post
    {
        $publicTypes = array_values(get_post_types(['public' => true]));

        // Try get_page_by_path with all public post types (handles hierarchical slugs)
        $page = get_page_by_path($path, OBJECT, $publicTypes);
        if ($page instanceof \WP_Post && $page->post_status === 'publish') {
            return $page;
        }

        // Fallback: try last slug segment across all public types
        $posts = get_posts([
            'name' => basename($path),
            'post_type' => $publicTypes,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);

        $post = $posts[0] ?? null;

        return $post instanceof \WP_Post ? $post : null;
    }

    private function servePost(\WP_Post $post): never
    {
        $seo = new SeoIntegration();

        // A password-protected post has no public Markdown twin. Serving one
        // would also poison the shared transient: the first visitor holding
        // the password would cache the full text for everybody else.
        if ($post->post_password !== '' || $seo->isNoindex($post->ID)) {
            status_header(404);
            exit;
        }

        $cacheKey = self::CACHE_PREFIX . $post->ID;
        $cached = get_transient($cacheKey);

        if (is_string($cached) && $cached !== '') {
            $this->serve($cached, $post);
        }

        $markdown = $this->buildMarkdown($post, new MarkdownConverter(), $seo);

        set_transient($cacheKey, $markdown, self::CACHE_TTL);
        $this->serve($markdown, $post);
    }

    /**
     * Build Markdown for a post with filter hooks for customization.
     *
     * Available filters:
     * - ai_visibility_markdown_meta (array $meta, WP_Post $post)
     *     Add custom key-value metadata lines (e.g. date, location, phone).
     *     Return: ['Key' => 'Value', ...] — appended after standard meta.
     *
     * - ai_visibility_markdown_before_content (string $text, WP_Post $post)
     *     Insert Markdown text before the main content (e.g. structured summary).
     *
     * - ai_visibility_markdown_content (string $content, WP_Post $post)
     *     Override or augment the converted HTML content.
     *     Receives the auto-converted Markdown. Return replacement.
     *
     * - ai_visibility_markdown (string $markdown, WP_Post $post)
     *     Final filter on the complete Markdown output.
     */
    private function buildMarkdown(\WP_Post $post, MarkdownConverter $converter, SeoIntegration $seo): string
    {
        $lines = [];
        $lines[] = '# ' . html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines[] = '';

        $desc = $seo->getDescription($post->ID);
        if ($desc !== '') {
            $lines[] = '> ' . $desc;
            $lines[] = '';
        }

        // Standard metadata
        $lines[] = 'URL: ' . get_permalink($post);
        $lines[] = 'Date: ' . get_the_date('Y-m-d', $post);
        $lines[] = 'Modified: ' . get_the_modified_date('Y-m-d', $post);

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

        // Main content (auto-converted from HTML)
        $content = Filters::string(
            'ai_visibility_markdown_content',
            $converter->convertPost($post),
            $post,
        );

        if ($content !== '') {
            $lines[] = $content;
        }

        return Filters::string('ai_visibility_markdown', implode("\n", $lines), $post);
    }

    private function serve(string $content, \WP_Post $post): never
    {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: public, max-age=' . HOUR_IN_SECONDS);
        header('X-Robots-Tag: noindex');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($post->post_name) . '.md"');

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown body, escaping would corrupt it.
        exit;
    }

    /**
     * Build the .md URL for a post (llms.txt convention).
     */
    public static function getMdUrl(int|\WP_Post $post): string
    {
        $postId = $post instanceof \WP_Post ? $post->ID : $post;
        $frontPage = self::frontPage();

        if ($frontPage instanceof \WP_Post && $frontPage->ID === $postId) {
            return home_url('/index.md');
        }

        $permalink = get_permalink($post);

        // A post with no permalink (bad ID, unregistered type) has no .md twin.
        if (!is_string($permalink)) {
            return '';
        }

        // /my-post/ → /my-post.md
        return rtrim($permalink, '/') . '.md';
    }

    /**
     * Send HTTP Link header advertising the Markdown version.
     */
    public function sendLinkHeader(): void
    {
        if (!is_singular() || get_query_var('format') === 'md') {
            return;
        }

        $url = self::alternateUrl();

        if ($url === null) {
            return;
        }

        header('Link: <' . $url . '>; rel="alternate"; type="text/markdown"', false);
    }

    /**
     * Render <link rel="alternate"> in HTML head for Markdown version.
     */
    public function renderLinkTag(): void
    {
        if (!is_singular()) {
            return;
        }

        $url = self::alternateUrl();

        if ($url === null) {
            return;
        }

        echo '<link rel="alternate" type="text/markdown" href="' . esc_url($url) . '">' . "\n";
    }

    /**
     * The Markdown URL to advertise for the post being viewed, if any.
     *
     * Returns null when the current post has no public Markdown twin — noindex,
     * password-protected, or without a resolvable permalink.
     */
    private static function alternateUrl(): ?string
    {
        $post = get_queried_object();

        if (!$post instanceof \WP_Post || $post->post_password !== '') {
            return null;
        }

        if ((new SeoIntegration())->isNoindex($post->ID)) {
            return null;
        }

        $url = self::getMdUrl($post);

        return $url === '' ? null : $url;
    }

    /**
     * Invalidate cache for a specific post.
     */
    public static function invalidateCache(int $postId): void
    {
        delete_transient(self::CACHE_PREFIX . $postId);
    }
}
