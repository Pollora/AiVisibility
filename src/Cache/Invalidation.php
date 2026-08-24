<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Cache;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Endpoint\MarkdownEndpoint;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\FileStore;

use const Pollora\AiVisibility\CRON_HOOK;

/**
 * Handles cache invalidation with debounced regeneration.
 */
final class Invalidation
{
    /**
     * Seconds to wait before regenerating, so a burst of saves costs one pass.
     */
    public const DEBOUNCE = 60;

    /**
     * Schedule a debounced regeneration after a post changed.
     */
    public function scheduleRegeneration(int $postId, ?\WP_Post $post = null): void
    {
        // Skip revisions and autosaves
        if (wp_is_post_revision($postId) !== false || wp_is_post_autosave($postId) !== false) {
            return;
        }

        $postType = $post instanceof \WP_Post ? $post->post_type : get_post_type($postId);

        if (!is_string($postType)) {
            return;
        }

        if (!in_array($postType, Plugin::settings()['post_types'], true)) {
            return;
        }

        // Invalidate individual Markdown cache
        MarkdownEndpoint::invalidateCache($postId);

        // Schedule debounced regeneration of static files
        if (wp_next_scheduled(CRON_HOOK) === false) {
            wp_schedule_single_event(time() + self::DEBOUNCE, CRON_HOOK);
        }
    }

    /**
     * Cron entry point. An action callback must not return anything.
     */
    public function regenerate(): void
    {
        $this->regenerateAll();
    }

    /**
     * Regenerate every static file. Called by wp_cron, WP-CLI or the admin.
     *
     * @return list<string> Names of the files that could not be written.
     */
    public function regenerateAll(): array
    {
        $failed = [];

        foreach (Artifact::cases() as $artifact) {
            if (!FileStore::write($artifact, $artifact->generate())) {
                $failed[] = $artifact->value;
            }
        }

        update_option('ai_visibility_last_generated', time());

        return $failed;
    }
}
