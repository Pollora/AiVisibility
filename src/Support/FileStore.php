<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Support;

use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Plugin;

/**
 * Reads and writes the generated files under wp-content/uploads/ai-visibility.
 *
 * Every operation can fail — a read-only uploads mount, a full disk, a
 * concurrent regeneration — and every failure is reported rather than
 * swallowed, so callers can fall back to generating on the fly instead of
 * serving an empty body.
 */
final class FileStore
{
    /**
     * File contents, or null when it does not exist or cannot be read.
     */
    public static function read(Artifact $artifact): ?string
    {
        $path = Plugin::filePath($artifact->value);

        if ($path === null || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Write the file atomically. Returns false when the write did not happen.
     *
     * The temporary file plus rename means a reader never sees a half-written
     * llms-full.txt, which on a large site takes a noticeable moment to build.
     */
    public static function write(Artifact $artifact, string $contents): bool
    {
        $path = Plugin::filePath($artifact->value);

        if ($path === null) {
            return false;
        }

        $temporary = $path . '.' . wp_generate_password(8, false) . '.tmp';

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return false;
        }

        if (!rename($temporary, $path)) {
            wp_delete_file($temporary);

            return false;
        }

        return true;
    }

    /**
     * Contents from disk, generating and caching them when the file is missing.
     */
    public static function readOrGenerate(Artifact $artifact): string
    {
        $cached = self::read($artifact);

        if ($cached !== null) {
            return $cached;
        }

        $contents = $artifact->generate();
        self::write($artifact, $contents);

        return $contents;
    }

    /**
     * Size in bytes and modification time, or null when the file is absent.
     *
     * @return array{size: int, mtime: int}|null
     */
    public static function stat(Artifact $artifact): ?array
    {
        $path = Plugin::filePath($artifact->value);

        if ($path === null || !is_readable($path)) {
            return null;
        }

        $stat = stat($path);

        if ($stat === false) {
            return null;
        }

        return ['size' => $stat['size'], 'mtime' => $stat['mtime']];
    }

    /**
     * Remove every generated file. Used when a feature is switched off.
     */
    public static function purge(): void
    {
        foreach (Artifact::cases() as $artifact) {
            $path = Plugin::filePath($artifact->value);

            if ($path !== null && is_file($path)) {
                wp_delete_file($path);
            }
        }
    }
}
