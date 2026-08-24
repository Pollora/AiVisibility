<?php

declare(strict_types=1);

namespace Pollora\AiVisibility;

use Pollora\AiVisibility\Generator\AiTxtGenerator;
use Pollora\AiVisibility\Generator\IdentityJsonGenerator;
use Pollora\AiVisibility\Generator\LlmsFullTxtGenerator;
use Pollora\AiVisibility\Generator\LlmsTxtGenerator;

/**
 * The four files the plugin generates.
 *
 * Endpoints, the WP-CLI command, the admin preview and the cron regeneration
 * all used to carry their own copy of this list. One authority means adding a
 * fifth artefact is a single edit, and nothing can drift.
 */
enum Artifact: string
{
    case LlmsTxt = 'llms.txt';
    case LlmsFullTxt = 'llms-full.txt';
    case AiTxt = 'ai.txt';
    case IdentityJson = 'identity.json';

    /**
     * The identifier accepted on the command line, e.g. `wp ai-visibility generate llms-txt`.
     */
    public function slug(): string
    {
        return match ($this) {
            self::LlmsTxt => 'llms-txt',
            self::LlmsFullTxt => 'llms-full-txt',
            self::AiTxt => 'ai-txt',
            self::IdentityJson => 'identity-json',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(static fn (self $case): string => $case->slug(), self::cases());
    }

    public function contentType(): string
    {
        return match ($this) {
            self::IdentityJson => 'application/json; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
    }

    /**
     * Public URL the file is served from.
     */
    public function url(): string
    {
        return match ($this) {
            self::LlmsTxt, self::LlmsFullTxt => home_url('/' . $this->value),
            default => home_url('/.well-known/' . $this->value),
        };
    }

    /**
     * How long a client may cache the file, in seconds.
     *
     * The llms files track content and are regenerated on save; the discovery
     * files describe the site itself and change on the order of months.
     */
    public function maxAge(): int
    {
        return match ($this) {
            self::LlmsTxt, self::LlmsFullTxt => HOUR_IN_SECONDS,
            default => DAY_IN_SECONDS,
        };
    }

    /**
     * Build the file's contents from the current site state.
     */
    public function generate(): string
    {
        return match ($this) {
            self::LlmsTxt => (new LlmsTxtGenerator())->generate(),
            self::LlmsFullTxt => (new LlmsFullTxtGenerator())->generate(),
            self::AiTxt => (new AiTxtGenerator())->generate(),
            self::IdentityJson => (new IdentityJsonGenerator())->generate(),
        };
    }
}
