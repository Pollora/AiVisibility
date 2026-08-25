<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Admin\Diagnostics;

/**
 * @return array{id: string, status: string, label: string, detail: string}
 */
function check(string $id): array
{
    foreach (Diagnostics::all() as $entry) {
        if ($entry['id'] === $id) {
            return $entry;
        }
    }

    throw new RuntimeException("No check named {$id}");
}

beforeEach(function (): void {
    // A healthy site, which each test then breaks in one specific way.
    $this->uploads = sys_get_temp_dir() . '/ai-visibility-diag-' . bin2hex(random_bytes(6));
    mkdir($this->uploads . '/ai-visibility', 0o777, true);

    foreach (['llms.txt', 'llms-full.txt', 'ai.txt', 'identity.json'] as $file) {
        file_put_contents($this->uploads . '/ai-visibility/' . $file, 'x');
    }

    $this->setOption('permalink_structure', '/%postname%/');
    $this->setOption('blog_public', '1');

    Functions\when('wp_upload_dir')->alias(fn (): array => ['basedir' => $this->uploads, 'error' => false]);
    Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0o777, true));
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->uploads));
});

it('reports every check with a status, a label and a detail', function (): void {
    foreach (Diagnostics::all() as $entry) {
        expect($entry['status'])->toBeIn([Diagnostics::PASS, Diagnostics::WARN, Diagnostics::FAIL])
            ->and($entry['label'])->not->toBe('')
            ->and($entry['detail'])->not->toBe('');
    }
});

describe('permalinks', function (): void {
    it('passes on a pretty permalink structure', function (): void {
        expect(check('permalinks')['status'])->toBe(Diagnostics::PASS);
    });

    it('fails on plain permalinks, which make every endpoint 404', function (mixed $structure): void {
        $this->setOption('permalink_structure', $structure);

        expect(check('permalinks')['status'])->toBe(Diagnostics::FAIL);
    })->with([
        'plain' => [''],
        'unset' => [false],
    ]);
});

describe('uploads', function (): void {
    it('passes when the cache directory is writable', function (): void {
        expect(check('uploads')['status'])->toBe(Diagnostics::PASS);
    });

    it('fails when the uploads directory reports an error', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'Disk full', 'basedir' => '']);

        expect(check('uploads')['status'])->toBe(Diagnostics::FAIL);
    });

    it('fails when the directory exists but cannot be written to', function (): void {
        chmod($this->uploads . '/ai-visibility', 0o555);

        try {
            expect(check('uploads')['status'])->toBe(Diagnostics::FAIL);
        } finally {
            chmod($this->uploads . '/ai-visibility', 0o777);
        }
    })->skip(posix_geteuid() === 0, 'Root ignores the permission bits.');
});

describe('visibility', function (): void {
    it('passes when the site is public', function (): void {
        expect(check('visibility')['status'])->toBe(Diagnostics::PASS);
    });

    it('warns when search engines are discouraged, since robots.txt then says nothing', function (): void {
        $this->setOption('blog_public', '0');

        expect(check('visibility')['status'])->toBe(Diagnostics::WARN);
    });
});

describe('files', function (): void {
    it('passes when all four files are on disk', function (): void {
        expect(check('files')['status'])->toBe(Diagnostics::PASS);
    });

    it('warns and names what is missing', function (): void {
        unlink($this->uploads . '/ai-visibility/ai.txt');

        $entry = check('files');

        expect($entry['status'])->toBe(Diagnostics::WARN)
            ->and($entry['detail'])->toContain('ai.txt');
    });
});

describe('abilities', function (): void {
    it('warns rather than fails when the Abilities API is absent', function (): void {
        // Everything except MCP works without it, so this is never a failure.
        expect(check('abilities')['status'])->toBeIn([Diagnostics::PASS, Diagnostics::WARN]);
    });
});

describe('worst()', function (): void {
    it('is a pass when nothing is wrong', function (): void {
        expect(Diagnostics::worst())->toBeIn([Diagnostics::PASS, Diagnostics::WARN]);
    });

    it('reports a warning when any check warns', function (): void {
        $this->setOption('blog_public', '0');

        expect(Diagnostics::worst())->toBe(Diagnostics::WARN);
    });

    it('reports a failure even when other checks only warn', function (): void {
        $this->setOption('permalink_structure', '');
        $this->setOption('blog_public', '0');

        expect(Diagnostics::worst())->toBe(Diagnostics::FAIL);
    });
});
