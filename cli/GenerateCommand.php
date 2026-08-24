<?php
/**
 * WP-CLI commands for AI Visibility.
 *
 * @package Pollora\AiVisibility
 */

declare(strict_types=1);

namespace Pollora\AiVisibility\CLI;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Cache\Invalidation;
use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\FileStore;

/**
 * Manage AI visibility files (llms.txt, llms-full.txt, ai.txt, identity.json).
 */
class GenerateCommand
{
    /**
     * Generate AI visibility files.
     *
     * ## OPTIONS
     *
     * <type>
     * : Which file to generate.
     * ---
     * options:
     *   - llms-txt
     *   - llms-full-txt
     *   - ai-txt
     *   - identity-json
     *   - all
     * ---
     *
     * [--dry-run]
     * : Output content to stdout without saving to disk.
     *
     * ## EXAMPLES
     *
     *     # Generate all files
     *     wp ai-visibility generate all
     *
     *     # Preview llms.txt without saving
     *     wp ai-visibility generate llms-txt --dry-run
     *
     *     # Regenerate only llms-full.txt
     *     wp ai-visibility generate llms-full-txt
     *
     * @subcommand generate
     *
     * @param  list<string>  $args
     * @param  array<string, bool|string>  $assocArgs
     */
    public function generate(array $args, array $assocArgs): void
    {
        $type = $args[0] ?? '';
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);

        if ($type === 'all') {
            $this->generateAll($dryRun);

            return;
        }

        $artifact = Artifact::fromSlug($type);

        if ($artifact === null) {
            \WP_CLI::error(sprintf(
                'Unknown file type "%s". Expected one of: %s.',
                $type,
                implode(', ', Artifact::slugs()),
            ));

            return;
        }

        $content = $artifact->generate();

        if ($dryRun) {
            \WP_CLI::log($content);

            return;
        }

        if (!FileStore::write($artifact, $content)) {
            \WP_CLI::error(sprintf(
                'Could not write %s. Check that %s is writable.',
                $artifact->value,
                Plugin::uploadDir() ?? 'the uploads directory',
            ));

            return;
        }

        \WP_CLI::success(sprintf(
            '%s generated (%s bytes) → %s',
            $artifact->value,
            number_format(strlen($content)),
            (string) Plugin::filePath($artifact->value),
        ));
    }

    /**
     * Show status of AI visibility files.
     *
     * ## EXAMPLES
     *
     *     wp ai-visibility status
     *
     * @subcommand status
     *
     * @param  list<string>  $args
     * @param  array<string, bool|string>  $assocArgs
     */
    public function status(array $args, array $assocArgs): void
    {
        $items = [];

        foreach (Artifact::cases() as $artifact) {
            $stat = FileStore::stat($artifact);

            $items[] = [
                'File' => $artifact->value,
                'Size' => $stat === null ? '-' : size_format($stat['size']),
                'Modified' => $stat === null ? '-' : wp_date('Y-m-d H:i:s', $stat['mtime']),
                'Status' => $stat === null ? '✗ Not generated' : '✓',
            ];
        }

        \WP_CLI\Utils\format_items('table', $items, ['File', 'Size', 'Modified', 'Status']);

        $lastGenerated = get_option('ai_visibility_last_generated', 0);
        if (is_numeric($lastGenerated) && (int) $lastGenerated > 0) {
            \WP_CLI::log('Last regeneration: ' . (string) wp_date('Y-m-d H:i:s', (int) $lastGenerated));
        }

        $settings = Plugin::settings();
        \WP_CLI::log('');
        \WP_CLI::log('Tracked post types: ' . (implode(', ', $settings['post_types']) ?: '(none)'));
        \WP_CLI::log('Posts per type limit: ' . $settings['posts_per_type']);
    }

    private function generateAll(bool $dryRun): void
    {
        if ($dryRun) {
            \WP_CLI::error('--dry-run is not supported with "all". Use a specific file type.');

            return;
        }

        \WP_CLI::log('Regenerating all AI visibility files…');

        $failed = (new Invalidation())->regenerateAll();

        if ($failed !== []) {
            \WP_CLI::error(sprintf(
                'Could not write: %s. Check that %s is writable.',
                implode(', ', $failed),
                Plugin::uploadDir() ?? 'the uploads directory',
            ));

            return;
        }

        \WP_CLI::success('All files regenerated in ' . (Plugin::uploadDir() ?? '?'));
    }
}

\WP_CLI::add_command('ai-visibility', GenerateCommand::class);
