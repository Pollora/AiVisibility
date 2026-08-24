<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Endpoint;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Support\FileStore;

/**
 * Serves llms.txt and llms-full.txt via WordPress rewrite rules.
 * Files are served from static cache on disk when available.
 */
final class LlmsTxtEndpoint
{
    public const QUERY_VAR = 'ai_visibility_file';

    public function registerRewriteRules(): void
    {
        add_rewrite_rule('^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=llms', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?' . self::QUERY_VAR . '=llms-full', 'top');

        add_filter('query_vars', [$this, 'registerQueryVar']);
    }

    /**
     * @param  array<int, string>  $vars
     * @return array<int, string>
     */
    public function registerQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    public function handleRequest(): void
    {
        $artifact = self::resolve(get_query_var(self::QUERY_VAR));

        if ($artifact === null) {
            return;
        }

        $this->serve($artifact, FileStore::readOrGenerate($artifact));
    }

    /**
     * Map the query var to the file it stands for.
     */
    private static function resolve(mixed $queryVar): ?Artifact
    {
        return match ($queryVar) {
            'llms' => Artifact::LlmsTxt,
            'llms-full' => Artifact::LlmsFullTxt,
            default => null,
        };
    }

    private function serve(Artifact $artifact, string $content): never
    {
        header('Content-Type: ' . $artifact->contentType());
        header('Cache-Control: public, max-age=' . $artifact->maxAge());
        header('X-Robots-Tag: noindex');

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text body, escaping would corrupt it.
        exit;
    }
}
