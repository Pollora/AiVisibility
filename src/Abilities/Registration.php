<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Abilities;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Cache\Invalidation;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\FileStore;

/**
 * Registers WordPress Abilities API entries for MCP integration.
 * Only loaded when wp_register_ability() exists (WP 6.9+).
 */
final class Registration
{
    public function registerCategories(): void
    {
        wp_register_ability_category('ai-visibility', [
            'label' => __('AI Visibility', 'amphibee-ai-visibility'),
            'description' => __('AI discoverability and visibility tools', 'amphibee-ai-visibility'),
        ]);
    }

    public function registerAbilities(): void
    {
        wp_register_ability('ai-visibility/get-llms-txt', $this->ability(
            __('Get llms.txt', 'amphibee-ai-visibility'),
            __('Returns the llms.txt content — a structured index of the site for LLMs', 'amphibee-ai-visibility'),
            static fn (): array => ['content' => FileStore::readOrGenerate(Artifact::LlmsTxt)],
            'read',
        ));

        wp_register_ability('ai-visibility/regenerate', $this->ability(
            __('Regenerate AI files', 'amphibee-ai-visibility'),
            __('Regenerates all AI visibility files (llms.txt, llms-full.txt, ai.txt, identity.json)', 'amphibee-ai-visibility'),
            static function (): array {
                $failed = (new Invalidation())->regenerateAll();

                return [
                    'status' => $failed === [] ? 'success' : 'partial',
                    'generated' => wp_date('Y-m-d H:i:s'),
                    'files' => array_map(static fn (Artifact $a): string => $a->value, Artifact::cases()),
                    'failed' => $failed,
                ];
            },
            'manage_options',
        ));

        wp_register_ability('ai-visibility/get-site-summary', $this->ability(
            __('Get site summary', 'amphibee-ai-visibility'),
            __('Returns a structured summary of the site for AI agents', 'amphibee-ai-visibility'),
            static function (): array {
                $counts = [];

                foreach (Plugin::settings()['post_types'] as $type) {
                    $countObject = wp_count_posts($type);
                    $published = is_object($countObject) ? ($countObject->publish ?? 0) : 0;
                    $counts[$type] = is_numeric($published) ? (int) $published : 0;
                }

                return [
                    'name' => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'url' => home_url('/'),
                    'language' => get_bloginfo('language'),
                    'post_counts' => $counts,
                    'llms_txt' => Artifact::LlmsTxt->url(),
                    'llms_full' => Artifact::LlmsFullTxt->url(),
                    'ai_txt' => Artifact::AiTxt->url(),
                    'identity' => Artifact::IdentityJson->url(),
                ];
            },
            'read',
        ));
    }

    /**
     * Shared shape of every ability this plugin exposes.
     *
     * @param  callable(): array<string, mixed>  $execute
     * @return array<string, mixed>
     */
    private function ability(string $label, string $description, callable $execute, string $capability): array
    {
        return [
            'label' => $label,
            'category' => 'ai-visibility',
            'description' => $description,
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            'execute_callback' => $execute,
            'permission_callback' => static fn (): bool => current_user_can($capability),
            'meta' => ['mcp' => ['public' => true]],
        ];
    }
}
