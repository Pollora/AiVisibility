<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Generator;

/**
 * The single query both llms.txt and llms-full.txt build their listings from.
 */
final class PostQuery
{
    /**
     * Published post IDs of one type, in the order they should be listed.
     *
     * Pages read as a hierarchy so menu_order/title beats the publication
     * date; everything else is a feed, newest first.
     *
     * @return list<int>
     */
    public static function published(string $postType, int $limit): array
    {
        $isHierarchical = is_post_type_hierarchical($postType);

        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'has_password' => false,
            'orderby' => $isHierarchical ? 'menu_order title' : 'date',
            'order' => $isHierarchical ? 'ASC' : 'DESC',
        ]);

        $ids = [];

        foreach ($posts as $post) {
            // 'fields' => 'ids' yields integers, but a pre_get_posts filter can
            // override it, and some object cache backends hand back numeric
            // strings. Accept both, ignore anything else.
            $id = $post instanceof \WP_Post ? $post->ID : $post;

            if (is_int($id)) {
                $ids[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
