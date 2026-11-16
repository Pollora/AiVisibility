<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
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

describe('exact markup', function (): void {
    it('renders a checked toggle', function (): void {
        expect(fieldMarkup(fn () => Field::toggle('enable_robots', 'Ro<b>bots', 'He"lp', true)))->toBe(
            '<div class="aivis-toggle">'
            . '<input type="checkbox" class="aivis-toggle__input" id="aivis-enable_robots" name="ai_visibility_settings[enable_robots]" value="1" checked=\'checked\'>'
            . '<label class="aivis-toggle__label" for="aivis-enable_robots">'
            . '<span class="aivis-toggle__track" aria-hidden="true"><span class="aivis-toggle__knob"></span></span>'
            . '<span class="aivis-toggle__text"><span class="aivis-toggle__title">Ro&lt;b&gt;bots</span>'
            . '<span class="aivis-toggle__help">He&quot;lp</span></span>'
            . '</label></div>',
        );
    });

    it('renders an unchecked toggle without the attribute', function (): void {
        expect(fieldMarkup(fn () => Field::toggle('k', 'L', 'H', false)))->toContain('value="1" >')
            ->not->toContain('checked');
    });

    it('asks checked() to return, not to echo', function (): void {
        $calls = [];
        Functions\when('checked')->alias(static function (...$args) use (&$calls): string {
            $calls[] = $args;

            return '';
        });

        fieldMarkup(fn () => Field::toggle('k', 'L', 'H', true));
        fieldMarkup(fn () => Field::typeCard('post_types', 'post', 'L', '1', true));

        expect($calls)->toBe([[true, true, false], [true, true, false]]);
    });

    it('renders an input', function (): void {
        expect(fieldMarkup(fn () => Field::input('identity_email', 'Em<i>ail', 'Wh"ere', 'a"b@c.test', 'email')))->toBe(
            '<div class="aivis-field">'
            . '<label class="aivis-field__label" for="aivis-identity_email">Em&lt;i&gt;ail</label>'
            . '<input type="email" class="aivis-field__control" id="aivis-identity_email" name="ai_visibility_settings[identity_email]" value="a&quot;b@c.test" aria-describedby="aivis-identity_email-help">'
            . '<p class="aivis-field__help" id="aivis-identity_email-help">Wh&quot;ere</p>'
            . '</div>',
        );
    });

    it('defaults an input to the text type', function (): void {
        expect(fieldMarkup(fn () => Field::input('k', 'L', 'H', 'v')))->toContain('<input type="text" ');
    });

    it('renders a textarea with its rows and placeholder', function (): void {
        expect(fieldMarkup(fn () => Field::textarea('crawlers_allow', 'Al<b>lowed', 'He"lp', "a<b>\nc", 8, 'Pl"ace')))->toBe(
            '<div class="aivis-field">'
            . '<label class="aivis-field__label" for="aivis-crawlers_allow">Al&lt;b&gt;lowed</label>'
            . '<textarea class="aivis-field__control aivis-field__control--area" id="aivis-crawlers_allow" name="ai_visibility_settings[crawlers_allow]" rows="8" '
            . 'placeholder="Pl&quot;ace" aria-describedby="aivis-crawlers_allow-help" spellcheck="false">a&lt;b&gt;' . "\n" . 'c</textarea>'
            . '<p class="aivis-field__help" id="aivis-crawlers_allow-help">He&quot;lp</p>'
            . '</div>',
        );
    });

    it('defaults a textarea to five rows and no placeholder', function (): void {
        expect(fieldMarkup(fn () => Field::textarea('k', 'L', 'H', 'v')))
            ->toContain('rows="5" placeholder="" ');
    });

    it('renders a number control with its bounds', function (): void {
        expect(fieldMarkup(fn () => Field::number('posts_per_type', 'Co<b>unt', 'He"lp', 50, 1, 200)))->toBe(
            '<div class="aivis-field aivis-field--narrow">'
            . '<label class="aivis-field__label" for="aivis-posts_per_type">Co&lt;b&gt;unt</label>'
            . '<input type="number" class="aivis-field__control aivis-field__control--number" id="aivis-posts_per_type" '
            . 'name="ai_visibility_settings[posts_per_type]" value="50" min="1" max="200" step="1" aria-describedby="aivis-posts_per_type-help">'
            . '<p class="aivis-field__help" id="aivis-posts_per_type-help">He&quot;lp</p>'
            . '</div>',
        );
    });

    it('renders a checked post type card', function (): void {
        expect(fieldMarkup(fn () => Field::typeCard('post_types', 'event', 'Ev<b>ents', '4 published', true)))->toBe(
            '<div class="aivis-card-check">'
            . '<input type="checkbox" class="aivis-card-check__input" id="aivis-post_types-event" name="ai_visibility_settings[post_types][]" value="event" checked=\'checked\'>'
            . '<label class="aivis-card-check__label" for="aivis-post_types-event">'
            . '<span class="aivis-card-check__name">Ev&lt;b&gt;ents</span>'
            . '<code class="aivis-card-check__slug">event</code>'
            . '<span class="aivis-card-check__count">4 published</span>'
            . '</label></div>',
        );
    });

    it('renders an unchecked post type card without the attribute', function (): void {
        expect(fieldMarkup(fn () => Field::typeCard('post_types', 'event', 'E', '0', false)))
            ->toContain('value="event" >')
            ->not->toContain('checked');
    });

    it('hands only strings to the escaping functions', function (): void {
        // The numbers reach esc_attr() as ints unless they are cast first; real
        // WordPress tolerates that, but a strict escaper would not.
        Functions\when('esc_attr')->alias(static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        expect(fieldMarkup(fn () => Field::number('k', 'L', 'H', 5, 2, 9)))->toContain('value="5" min="2" max="9"')
            ->and(fieldMarkup(fn () => Field::textarea('k', 'L', 'H', 'v', 3)))->toContain('rows="3"');
    });
});
