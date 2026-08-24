<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\RobotsTxt;

use Pollora\AiVisibility\Plugin;

/**
 * Adds AI crawler directives to WordPress virtual robots.txt.
 */
final class AiDirectives
{
    /**
     * @param  mixed  $public  Whether the site is indexable. WordPress passes
     *                         get_option('blog_public') here, which is the
     *                         string "1" or "0" — not a boolean. Declaring
     *                         `bool` only worked because core calls the filter
     *                         from a file without strict_types. The string "0"
     *                         is falsy in PHP, so one truthiness test covers
     *                         both the string and the boolean form.
     */
    public function addDirectives(string $output, mixed $public): string
    {
        if (!$public) {
            return $output;
        }

        $settings = Plugin::settings();
        $lines = [];

        // Allow directives
        $allowed = $settings['crawlers_allow'];
        if ($allowed !== []) {
            $lines[] = '';
            $lines[] = '# AI Search Crawlers (allowed)';
            foreach ($allowed as $bot) {
                $lines[] = "User-agent: {$bot}";
                $lines[] = 'Allow: /';
                $lines[] = '';
            }
        }

        // Block directives
        $blocked = $settings['crawlers_block'];
        if ($blocked !== []) {
            $lines[] = '# AI Training Crawlers (blocked)';
            foreach ($blocked as $bot) {
                $lines[] = "User-agent: {$bot}";
                $lines[] = 'Disallow: /';
                $lines[] = '';
            }
        }

        // Reference to llms.txt
        $siteUrl = home_url('/');
        $lines[] = '# AI Discovery';
        $lines[] = "# llms.txt: {$siteUrl}llms.txt";
        $lines[] = "# llms-full.txt: {$siteUrl}llms-full.txt";

        return $output . implode("\n", $lines) . "\n";
    }
}
