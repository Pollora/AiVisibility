<?php

declare(strict_types=1);

/**
 * Guards the metadata a distributed plugin is judged on.
 *
 * Version strings, the text domain and the PHP requirement live in four
 * different files. Nothing in PHP enforces that they agree, and a mismatch is
 * invisible until an update ships to real sites.
 */
$root = dirname(__DIR__, 2);

/**
 * @return array<string, string>
 */
function pluginHeader(string $file): array
{
    $source = (string) file_get_contents($file);
    $header = substr($source, 0, 8192);

    preg_match_all('/^\s*\*\s*([A-Za-z][A-Za-z ]*?):\s*(.+?)\s*$/m', $header, $matches, PREG_SET_ORDER);

    $fields = [];

    foreach ($matches as $match) {
        $fields[$match[1]] = $match[2];
    }

    return $fields;
}

beforeEach(function () use ($root): void {
    $this->root = $root;
    $this->header = pluginHeader($root . '/ai-visibility.php');
    $this->composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
});

describe('plugin header', function (): void {
    it('declares every field WordPress needs to list the plugin', function (string $field): void {
        expect($this->header)->toHaveKey($field)
            ->and($this->header[$field])->not->toBe('');
    })->with([
        'Plugin Name',
        'Description',
        'Version',
        'Author',
        'License',
        'Text Domain',
        'Requires PHP',
        'Requires at least',
        'Domain Path',
    ]);

    it('uses a semantic version', function (): void {
        expect($this->header['Version'])->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/');
    });

    it('uses the text domain the code actually calls', function (): void {
        expect($this->header['Text Domain'])->toBe('ai-visibility');
    });

    it('requires the PHP version composer.json requires', function (): void {
        expect('>=' . $this->header['Requires PHP'])->toBe($this->composer['require']['php']);
    });

    it('names a GPL-compatible licence matching composer.json', function (): void {
        expect($this->header['License'])->toBe($this->composer['license']);
    });
});

describe('version consistency', function (): void {
    it('declares the same version in the header and the VERSION constant', function (): void {
        $source = (string) file_get_contents($this->root . '/ai-visibility.php');

        preg_match("/const VERSION = '([^']+)'/", $source, $matches);

        expect($matches[1] ?? null)->toBe($this->header['Version']);
    });

    it('declares the same version in the changelog', function (): void {
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');

        expect($changelog)->toContain('## ' . $this->header['Version']);
    });
});

describe('plugin constants', function () use ($root): void {
    it('keeps the test fixture in step with the real constants', function (string $name, string $expected) use ($root): void {
        $source = (string) file_get_contents($root . '/ai-visibility.php');

        preg_match("/const {$name} = '([^']+)'/", $source, $matches);

        expect($matches[1] ?? null)->toBe($expected);
    })->with([
        ['OPTION_KEY', 'ai_visibility_settings'],
        ['UPLOAD_DIR', 'ai-visibility'],
        ['CRON_HOOK', 'ai_visibility_regenerate'],
    ]);

    it('uninstalls exactly the options the plugin creates', function () use ($root): void {
        $uninstall = (string) file_get_contents($root . '/uninstall.php');

        foreach (['ai_visibility_settings', 'ai_visibility_last_generated', 'ai_visibility_flush_rewrite'] as $option) {
            expect($uninstall)->toContain("delete_option('{$option}')");
        }
    });

    it('clears the cron event on uninstall as well as on deactivation', function () use ($root): void {
        expect((string) file_get_contents($root . '/uninstall.php'))
            ->toContain("wp_clear_scheduled_hook('ai_visibility_regenerate')");
    });
});

describe('composer metadata', function (): void {
    it('is a wordpress-plugin package so composer/installers places it correctly', function (): void {
        expect($this->composer['type'])->toBe('wordpress-plugin');
    });

    it('autoloads the namespace the code declares', function (): void {
        expect($this->composer['autoload']['psr-4'])->toHaveKey('Pollora\\AiVisibility\\');
    });

    it('pins the platform PHP to the minimum supported version', function (): void {
        expect($this->composer['config']['platform']['php'])->toStartWith('8.3');
    });

    it('keeps every quality tool in require-dev, never in require', function (): void {
        expect(array_keys($this->composer['require']))->toBe(['php']);
    });
});

describe('shipped files', function () use ($root): void {
    it('marks development files as export-ignore so they stay out of the zip', function (string $path) use ($root): void {
        expect((string) file_get_contents($root . '/.gitattributes'))->toContain($path);
    })->with(['tests', 'phpunit.xml.dist', 'phpstan.neon.dist', 'phpcs.xml.dist', 'pint.json', '.github']);

    it('ships an index.php guard in every PHP directory', function (string $directory) use ($root): void {
        expect($root . '/' . $directory . '/index.php')->toBeReadableFile();
    })->with(['src', 'cli', 'assets']);

    it('carries a licence file', function () use ($root): void {
        expect($root . '/LICENSE')->toBeReadableFile();
    });

    it('ships a translation template at the advertised Domain Path', function () use ($root): void {
        expect($root . '/languages/ai-visibility.pot')->toBeReadableFile();
    });

    it('declares every translatable string in the template', function () use ($root): void {
        $template = (string) file_get_contents($root . '/languages/ai-visibility.pot');

        expect($template)
            ->toContain('"X-Domain: ai-visibility')
            ->toContain('msgid "AI Visibility"');
    });

    it('never leaves a debugging call in shipped code', function () use ($root): void {
        $offenders = [];

        foreach (['src', 'cli'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/\b(var_dump|print_r|error_log|die\s*\()\s*\(/', $source) === 1) {
                    $offenders[] = $file->getFilename();
                }
            }
        }

        expect($offenders)->toBe([]);
    });
});

describe('internationalisation', function () use ($root): void {
    it('uses only its own text domain', function () use ($root): void {
        $wrong = [];

        foreach (['src', 'cli'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                preg_match_all("/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|_x|_n)\([^)]*?,\s*'([a-z0-9-]+)'\s*\)/", $source, $matches);

                foreach ($matches[1] as $domain) {
                    if ($domain !== 'ai-visibility') {
                        $wrong[] = $file->getFilename() . ': ' . $domain;
                    }
                }
            }
        }

        expect($wrong)->toBe([]);
    });
});
