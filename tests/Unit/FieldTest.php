<?php

declare(strict_types=1);

use Pollora\AiVisibility\Admin\Field;

/** Capture what a field echoes. */
function fieldMarkup(callable $render): string
{
    ob_start();
    $render();

    return (string) ob_get_clean();
}

it('names every control under the plugin option, so nothing else is overwritten', function (callable $render): void {
    expect(fieldMarkup($render))->toContain('name="ai_visibility_settings[');
})->with([
    'toggle' => [fn () => Field::toggle('enable_robots', 'Robots', 'Help', true)],
    'input' => [fn () => Field::input('identity_email', 'Email', 'Help', 'a@b.test', 'email')],
    'textarea' => [fn () => Field::textarea('site_description', 'About', 'Help', 'Text')],
    'number' => [fn () => Field::number('posts_per_type', 'Count', 'Help', 50, 1, 200)],
    'type card' => [fn () => Field::typeCard('post_types', 'post', 'Posts', '4 published', true)],
]);

it('gives every control a label bound to its input', function (callable $render): void {
    $markup = fieldMarkup($render);

    preg_match('/id="([^"]+)"/', $markup, $id);
    expect($markup)->toContain('for="' . $id[1] . '"');
})->with([
    'toggle' => [fn () => Field::toggle('enable_robots', 'Robots', 'Help', true)],
    'input' => [fn () => Field::input('identity_email', 'Email', 'Help', '')],
    'textarea' => [fn () => Field::textarea('site_description', 'About', 'Help', '')],
    'number' => [fn () => Field::number('posts_per_type', 'Count', 'Help', 50, 1, 200)],
    'type card' => [fn () => Field::typeCard('post_types', 'post', 'Posts', '4', false)],
]);

describe('the switch', function (): void {
    it('is a real checkbox, so it submits and reaches the tab order without JavaScript', function (): void {
        expect(fieldMarkup(fn () => Field::toggle('enable_robots', 'Robots', 'Help', true)))
            ->toContain('type="checkbox"')
            ->toContain('value="1"');
    });

    it('reflects the stored value', function (bool $checked): void {
        $markup = fieldMarkup(fn () => Field::toggle('enable_robots', 'Robots', 'Help', $checked));

        $checked
            ? expect($markup)->toContain('checked')
            : expect($markup)->not->toContain('checked');
    })->with([true, false]);

    it('hides the decorative track from assistive technology', function (): void {
        expect(fieldMarkup(fn () => Field::toggle('enable_robots', 'Robots', 'Help', true)))
            ->toContain('aria-hidden="true"');
    });
});

describe('escaping', function (): void {
    it('never lets a stored value break out of an attribute', function (): void {
        $markup = fieldMarkup(fn () => Field::input('identity_email', 'Email', 'Help', '"><script>alert(1)</script>'));

        expect($markup)->not->toContain('<script>')
            ->and($markup)->not->toContain('"><script');
    });

    it('never lets a stored value break out of a textarea', function (): void {
        $markup = fieldMarkup(fn () => Field::textarea('site_description', 'About', 'Help', '</textarea><script>alert(1)</script>'));

        expect($markup)->not->toContain('<script>')
            ->and(substr_count($markup, '</textarea>'))->toBe(1);
    });

    it('escapes labels and help text', function (): void {
        $markup = fieldMarkup(fn () => Field::toggle('k', '<b>Label</b>', '<i>Help</i>', false));

        expect($markup)->not->toContain('<b>')->not->toContain('<i>');
    });

    it('escapes a post type slug coming from a third-party plugin', function (): void {
        $markup = fieldMarkup(fn () => Field::typeCard('post_types', '"><img src=x>', 'Label', '0', false));

        expect($markup)->not->toContain('<img');
    });
});

describe('the number control', function (): void {
    it('carries its bounds in the markup, not only in the sanitiser', function (): void {
        expect(fieldMarkup(fn () => Field::number('posts_per_type', 'Count', 'Help', 50, 1, 200)))
            ->toContain('type="number"')
            ->toContain('min="1"')
            ->toContain('max="200"')
            ->toContain('value="50"');
    });
});

describe('help text', function (): void {
    it('is announced with the control it describes', function (): void {
        $markup = fieldMarkup(fn () => Field::input('identity_email', 'Email', 'Where to write', ''));

        expect($markup)->toContain('aria-describedby="aivis-identity_email-help"')
            ->toContain('id="aivis-identity_email-help"');
    });
});

describe('name()', function (): void {
    it('builds the option array key', function (): void {
        expect(Field::name('enable_robots'))->toBe('ai_visibility_settings[enable_robots]');
    });

    it('builds a multi-value key for checkbox groups', function (): void {
        expect(Field::name('post_types', true))->toBe('ai_visibility_settings[post_types][]');
    });
});
