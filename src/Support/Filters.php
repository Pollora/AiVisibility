<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Support;

/**
 * Type-safe wrappers around apply_filters().
 *
 * A third-party callback can return anything at all — a null from a `return;`
 * that fell through, an array from a copy-pasted hook. Without a guard that
 * value flows straight into string concatenation and takes the whole request
 * down. These helpers keep a misbehaving filter from breaking the site: the
 * unfiltered value is used instead.
 */
final class Filters
{
    /**
     * Apply a filter that must yield a string.
     *
     * @param  non-empty-string  $hook
     */
    public static function string(string $hook, string $value, mixed ...$args): string
    {
        $filtered = apply_filters($hook, $value, ...$args);

        return is_string($filtered) ? $filtered : $value;
    }

    /**
     * Apply a filter that must yield an array of scalar metadata lines.
     *
     * @param  non-empty-string  $hook
     * @return array<string, string>
     */
    public static function metaLines(string $hook, mixed ...$args): array
    {
        $filtered = apply_filters($hook, [], ...$args);

        if (!is_array($filtered)) {
            return [];
        }

        $lines = [];

        foreach ($filtered as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_string($value) && $value !== '') {
                $lines[$key] = $value;

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $lines[$key] = (string) $value;
            }
        }

        return $lines;
    }

    /**
     * Apply a filter that must yield an array.
     *
     * @param  non-empty-string  $hook
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public static function array(string $hook, array $value, mixed ...$args): array
    {
        $filtered = apply_filters($hook, $value, ...$args);

        return is_array($filtered) ? $filtered : $value;
    }
}
