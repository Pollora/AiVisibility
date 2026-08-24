<?php
/**
 * Minimal stand-ins for the WordPress classes the plugin type-hints against.
 *
 * Only the properties the plugin actually reads are declared. They exist to let
 * `instanceof \WP_Post` succeed in a process where WordPress was never loaded;
 * behaviour lives in Brain Monkey function mocks, not here.
 *
 * @package Pollora\AiVisibility
 */

declare(strict_types=1);

if (!class_exists('WP_Post')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_post/
     */
    class WP_Post
    {
        public int $ID = 0;

        public string $post_title = '';

        public string $post_name = '';

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_type = 'post';

        public string $post_status = 'publish';

        public string $post_password = '';

        public int $menu_order = 0;

        /**
         * @param  array<string, int|string>  $properties
         */
        public function __construct(array $properties = [])
        {
            foreach ($properties as $name => $value) {
                if (property_exists($this, $name)) {
                    $this->$name = $value;
                }
            }
        }
    }
}

if (!class_exists('WP_Post_Type')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp_post_type/
     */
    class WP_Post_Type
    {
        public string $name = '';

        public string $label = '';

        public \stdClass $labels;

        public bool $hierarchical = false;

        public function __construct(string $name = '', string $label = '', ?string $pluralLabel = null)
        {
            $this->name = $name;
            $this->label = $label;
            $this->labels = (object) ['name' => $pluralLabel ?? $label];
        }
    }
}

if (!class_exists('WP')) {
    /**
     * @see https://developer.wordpress.org/reference/classes/wp/
     */
    class WP
    {
        public string $request = '';

        public function __construct(string $request = '')
        {
            $this->request = $request;
        }
    }
}
