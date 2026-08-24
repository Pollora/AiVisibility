<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\AiVisibility\Artifact;
use Pollora\AiVisibility\Cache\Invalidation;

beforeEach(function (): void {
    $this->invalidation = new Invalidation();

    $this->setSettings(['post_types' => ['post', 'page']]);

    Functions\when('wp_is_post_revision')->justReturn(false);
    Functions\when('wp_is_post_autosave')->justReturn(false);
    Functions\when('get_post_type')->justReturn('post');
    Functions\when('wp_next_scheduled')->justReturn(false);
});

describe('scheduleRegeneration', function (): void {
    it('schedules a single debounced event', function (): void {
        Functions\expect('delete_transient')->once()->with('ai_vis_md_42');
        Functions\expect('wp_schedule_single_event')
            ->once()
            ->withArgs(static fn (int $when, string $hook): bool => $hook === 'ai_visibility_regenerate'
                && $when > time());

        $this->invalidation->scheduleRegeneration(42);
    });

    it('debounces: a burst of saves schedules at most one run', function (): void {
        Functions\when('wp_next_scheduled')->justReturn(time() + 30);
        Functions\when('delete_transient')->justReturn(true);
        Functions\expect('wp_schedule_single_event')->never();

        $this->invalidation->scheduleRegeneration(1);
        $this->invalidation->scheduleRegeneration(2);
        $this->invalidation->scheduleRegeneration(3);
    });

    it('waits long enough to batch an editor saving repeatedly', function (): void {
        expect(Invalidation::DEBOUNCE)->toBeGreaterThanOrEqual(30);
    });

    it('ignores revisions and autosaves', function (string $function): void {
        Functions\when($function)->justReturn(7);
        Functions\expect('wp_schedule_single_event')->never();
        Functions\expect('delete_transient')->never();

        $this->invalidation->scheduleRegeneration(42);
    })->with(['wp_is_post_revision', 'wp_is_post_autosave']);

    it('ignores post types the site does not track', function (): void {
        Functions\when('get_post_type')->justReturn('shop_order');
        Functions\expect('wp_schedule_single_event')->never();
        Functions\expect('delete_transient')->never();

        $this->invalidation->scheduleRegeneration(42);
    });

    it('ignores a post whose type cannot be resolved', function (): void {
        Functions\when('get_post_type')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->never();

        $this->invalidation->scheduleRegeneration(42);
    });

    it('uses the post object when WordPress passes one, sparing a lookup', function (): void {
        Functions\expect('get_post_type')->never();
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_schedule_single_event')->justReturn(true);

        $this->invalidation->scheduleRegeneration(42, $this->post(42, 'A page', '', ['post_type' => 'page']));
    });

    it('drops the post Markdown cache before anything else', function (): void {
        Functions\expect('delete_transient')->once()->with('ai_vis_md_42');
        Functions\when('wp_schedule_single_event')->justReturn(true);

        $this->invalidation->scheduleRegeneration(42);
    });
});

describe('regenerateAll', function (): void {
    beforeEach(function (): void {
        $this->uploads = sys_get_temp_dir() . '/ai-visibility-inv-' . bin2hex(random_bytes(6));
        mkdir($this->uploads, 0o777, true);

        Functions\when('wp_upload_dir')->alias(fn (): array => ['basedir' => $this->uploads, 'error' => false]);
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => is_dir($d) || mkdir($d, 0o777, true));
        Functions\when('wp_delete_file')->alias(static function (string $p): void {
            if (is_file($p)) {
                unlink($p);
            }
        });
        Functions\when('get_post_type_object')->justReturn(null);
    });

    afterEach(function (): void {
        exec('rm -rf ' . escapeshellarg($this->uploads));
    });

    it('writes every artefact', function (): void {
        expect($this->invalidation->regenerateAll())->toBe([]);

        foreach (Artifact::cases() as $artifact) {
            expect($this->uploads . '/ai-visibility/' . $artifact->value)->toBeReadableFile();
        }
    });

    it('records when it last ran', function (): void {
        $this->invalidation->regenerateAll();

        expect($this->optionValues['ai_visibility_last_generated'])->toBeGreaterThan(0);
    });

    it('names the files it could not write instead of failing silently', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'read-only file system', 'basedir' => '']);

        expect($this->invalidation->regenerateAll())
            ->toBe(['llms.txt', 'llms-full.txt', 'ai.txt', 'identity.json']);
    });

    it('still records the run so the admin sees the attempt', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'read-only file system', 'basedir' => '']);

        $this->invalidation->regenerateAll();

        expect($this->optionValues)->toHaveKey('ai_visibility_last_generated');
    });

    it('returns nothing from the cron entry point', function (): void {
        expect($this->invalidation->regenerate())->toBeNull();
    });
});
