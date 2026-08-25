<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base case for the unit suite: Brain Monkey up, plus the handful of WordPress
 * functions that nearly every code path touches.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Values returned by the mocked get_option(), keyed by option name.
     *
     * @var array<string, mixed>
     */
    protected array $optionValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->optionValues = [];

        $this->stubEscaping();
        $this->stubOptions();
        $this->stubSiteInfo();
        $this->stubAbsentPlugins();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Set the value get_option() will return for a given name.
     */
    protected function setOption(string $name, mixed $value): void
    {
        $this->optionValues[$name] = $value;
    }

    /**
     * Store plugin settings as they would be found in the database.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function setSettings(array $settings): void
    {
        $this->setOption('ai_visibility_settings', $settings);
    }

    /**
     * Build a post double.
     */
    protected function post(int $id, string $title = 'A title', string $content = '', array $extra = []): \WP_Post
    {
        return new \WP_Post([
            'ID' => $id,
            'post_title' => $title,
            'post_name' => sanitize_title_stub($title),
            'post_content' => $content,
            ...$extra,
        ]);
    }

    /**
     * Escaping and sanitising helpers behave as identity or as their real
     * PHP equivalent — enough for assertions about structure, and honest
     * about what actually gets escaped.
     */
    private function stubEscaping(): void
    {
        Functions\when('esc_html')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_textarea')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(static fn ($t) => filter_var((string) $t, FILTER_SANITIZE_URL) ?: '');
        Functions\when('esc_url_raw')->alias(static fn ($t) => filter_var((string) $t, FILTER_SANITIZE_URL) ?: '');
        Functions\when('esc_html__')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr__')->alias(static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html_e')->alias(static function ($t): void {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        });
        Functions\when('__')->returnArg(1);
        Functions\when('_e')->alias(static function ($t): void {
            echo (string) $t;
        });
        Functions\when('sanitize_text_field')->alias(static fn ($t) => trim(strip_tags((string) $t)));
        Functions\when('sanitize_textarea_field')->alias(static fn ($t) => trim(strip_tags((string) $t)));
        Functions\when('sanitize_email')->alias(
            static fn ($e) => filter_var((string) $e, FILTER_VALIDATE_EMAIL) ?: '',
        );
        Functions\when('sanitize_key')->alias(
            static fn ($k) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)) ?? '',
        );
        Functions\when('wp_unslash')->alias(static fn ($v) => is_string($v) ? stripslashes($v) : $v);
        Functions\when('sanitize_file_name')->alias(
            static fn ($n) => preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $n) ?? '',
        );
        Functions\when('wp_strip_all_tags')->alias(static fn ($t) => trim(strip_tags((string) $t)));
        Functions\when('checked')->alias(
            static fn ($a, $b = true, $echo = true): string => $a === $b ? "checked='checked'" : '',
        );
        Functions\when('wp_json_encode')->alias(
            static fn ($data, $flags = 0) => json_encode($data, (int) $flags),
        );
        Functions\when('wp_generate_password')->alias(
            static fn (int $length = 12): string => substr(str_repeat('a1b2c3d4', 4), 0, $length),
        );
        Functions\when('wp_date')->alias(
            static fn (string $format, ?int $timestamp = null): string => gmdate($format, $timestamp ?? 0),
        );
        Functions\when('size_format')->alias(static fn ($bytes) => $bytes . ' B');
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1) => parse_url($url, $component),
        );
    }

    private function stubOptions(): void
    {
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->optionValues[$name] ?? $default,
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->optionValues[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->optionValues[$name]);

            return true;
        });
    }

    /**
     * Third-party integrations are absent unless a test says otherwise.
     *
     * Brain Monkey leaves a mocked function defined for the remainder of the
     * process, so `function_exists()` stays true in every test that runs after
     * one mocks it. Declaring the default here keeps that leak from turning
     * test order into a source of failures.
     */
    private function stubAbsentPlugins(): void
    {
        Functions\when('the_seo_framework')->justReturn(null);
    }

    private function stubSiteInfo(): void
    {
        Functions\when('home_url')->alias(
            static fn (string $path = '') => rtrim('https://example.test', '/') . '/' . ltrim($path, '/'),
        );
        Functions\when('get_bloginfo')->alias(static fn (string $show) => match ($show) {
            'name' => 'Example Site',
            'description' => 'Just another example',
            'language' => 'en-US',
            default => '',
        });
    }
}

if (!function_exists('Pollora\AiVisibility\Tests\sanitize_title_stub')) {
    function sanitize_title_stub(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-');
    }
}
