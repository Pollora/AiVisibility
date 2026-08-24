<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Support\FileStore;

/**
 * These tests hit a real temporary directory rather than a virtual filesystem:
 * the atomic write depends on rename() semantics, which only a real one has.
 */
beforeEach(function (): void {
    $this->uploads = sys_get_temp_dir() . '/ai-visibility-tests-' . bin2hex(random_bytes(6));
    mkdir($this->uploads . '/ai-visibility', 0o777, true);

    Functions\when('wp_upload_dir')->alias(fn (): array => [
        'basedir' => $this->uploads,
        'error' => false,
    ]);
    Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0o777, true));
    Functions\when('wp_delete_file')->alias(static function (string $path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });
});

afterEach(function (): void {
    if (is_dir($this->uploads)) {
        exec('rm -rf ' . escapeshellarg($this->uploads));
    }
});

it('writes and reads a file back verbatim', function (): void {
    $contents = "# Example Site\n\n> Tagline with émoji 🎉 and a trailing newline\n";

    expect(FileStore::write(Artifact::LlmsTxt, $contents))->toBeTrue()
        ->and(FileStore::read(Artifact::LlmsTxt))->toBe($contents);
});

it('reports null for a file that was never written', function (): void {
    expect(FileStore::read(Artifact::AiTxt))->toBeNull();
});

it('leaves no temporary files behind after a successful write', function (): void {
    FileStore::write(Artifact::LlmsTxt, 'content');

    $leftovers = glob($this->uploads . '/ai-visibility/*.tmp');

    expect($leftovers)->toBe([]);
});

it('overwrites an existing file rather than appending', function (): void {
    FileStore::write(Artifact::LlmsTxt, 'first version, quite long');
    FileStore::write(Artifact::LlmsTxt, 'second');

    expect(FileStore::read(Artifact::LlmsTxt))->toBe('second');
});

it('reports failure instead of throwing when the uploads directory is unusable', function (): void {
    Functions\when('wp_upload_dir')->justReturn(['error' => 'No space left on device', 'basedir' => '']);

    expect(FileStore::write(Artifact::LlmsTxt, 'content'))->toBeFalse()
        ->and(FileStore::read(Artifact::LlmsTxt))->toBeNull()
        ->and(FileStore::stat(Artifact::LlmsTxt))->toBeNull();
});

describe('readOrGenerate', function (): void {
    it('serves the cached file without regenerating', function (): void {
        FileStore::write(Artifact::LlmsTxt, 'cached body');

        // No generator stubs are registered: calling one would fail the test.
        expect(FileStore::readOrGenerate(Artifact::LlmsTxt))->toBe('cached body');
    });

    it('generates and caches when the file is missing', function (): void {
        Functions\when('get_post_type_object')->justReturn(null);

        $generated = FileStore::readOrGenerate(Artifact::LlmsTxt);

        expect($generated)->toContain('# Example Site')
            ->and(FileStore::read(Artifact::LlmsTxt))->toBe($generated);
    });

    it('still returns content when the cache cannot be written', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'read-only', 'basedir' => '']);
        Functions\when('get_post_type_object')->justReturn(null);

        expect(FileStore::readOrGenerate(Artifact::LlmsTxt))->toContain('# Example Site');
    });
});

describe('stat', function (): void {
    it('reports the size of a written file', function (): void {
        FileStore::write(Artifact::AiTxt, str_repeat('x', 128));

        $stat = FileStore::stat(Artifact::AiTxt);

        expect($stat)->not->toBeNull()
            ->and($stat['size'])->toBe(128)
            ->and($stat['mtime'])->toBeGreaterThan(0);
    });

    it('returns null for a missing file', function (): void {
        expect(FileStore::stat(Artifact::IdentityJson))->toBeNull();
    });
});

it('purges every generated file', function (): void {
    foreach (Artifact::cases() as $artifact) {
        FileStore::write($artifact, 'content');
    }

    FileStore::purge();

    foreach (Artifact::cases() as $artifact) {
        expect(FileStore::read($artifact))->toBeNull();
    }
});

it('creates the plugin subdirectory when it does not exist yet', function (): void {
    exec('rm -rf ' . escapeshellarg($this->uploads . '/ai-visibility'));

    expect(FileStore::write(Artifact::LlmsTxt, 'content'))->toBeTrue()
        ->and(is_dir($this->uploads . '/ai-visibility'))->toBeTrue();
});
