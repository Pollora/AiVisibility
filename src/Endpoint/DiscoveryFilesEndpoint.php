<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Endpoint;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Support\FileStore;

/**
 * Serves AI discovery files.
 *
 * Registers both .well-known/ paths (standard) and ai-discovery/ paths (fallback
 * for servers that block dotfiles like nginx's default `location ~ /\.` rule).
 */
final class DiscoveryFilesEndpoint
{
    public const QUERY_VAR = 'ai_visibility_discovery';

    public function registerRewriteRules(): void
    {
        foreach ([Artifact::AiTxt, Artifact::IdentityJson] as $artifact) {
            $file = preg_quote($artifact->value, '/');
            $target = 'index.php?' . self::QUERY_VAR . '=' . $artifact->slug();

            // Standard .well-known path (may be blocked by nginx dotfile rules)
            add_rewrite_rule('^\.well-known/' . $file . '$', $target, 'top');

            // Fallback path (always works)
            add_rewrite_rule('^ai-discovery/' . $file . '$', $target, 'top');
        }

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
        $queryVar = get_query_var(self::QUERY_VAR);

        if (!is_string($queryVar) || $queryVar === '') {
            return;
        }

        $artifact = Artifact::fromSlug($queryVar);

        if ($artifact === null || !in_array($artifact, [Artifact::AiTxt, Artifact::IdentityJson], true)) {
            return;
        }

        $this->serve($artifact, FileStore::readOrGenerate($artifact));
    }

    private function serve(Artifact $artifact, string $content): never
    {
        header('Content-Type: ' . $artifact->contentType());
        header('Cache-Control: public, max-age=' . $artifact->maxAge());

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text and JSON bodies, escaping would corrupt them.
        exit;
    }
}
