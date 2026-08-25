<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Admin;

use const Pollora\AiVisibility\OPTION_KEY;

/**
 * Form controls for the settings screen.
 *
 * Every control is a real form element with a real label. The switch is a
 * checkbox the CSS draws differently, not a div listening for clicks: it
 * submits without JavaScript, it reaches the tab order for free, and a screen
 * reader announces it as the checkbox it is.
 */
final class Field
{
    /**
     * Name attribute for one setting, e.g. ai_visibility_settings[enable_robots].
     */
    public static function name(string $key, bool $multiple = false): string
    {
        return OPTION_KEY . '[' . $key . ']' . ($multiple ? '[]' : '');
    }

    /**
     * An on/off switch with its label and supporting text.
     */
    public static function toggle(string $key, string $label, string $help, bool $checked): void
    {
        $id = 'aivis-' . $key;

        printf(
            '<div class="aivis-toggle">'
            . '<input type="checkbox" class="aivis-toggle__input" id="%1$s" name="%2$s" value="1" %3$s>'
            . '<label class="aivis-toggle__label" for="%1$s">'
            . '<span class="aivis-toggle__track" aria-hidden="true"><span class="aivis-toggle__knob"></span></span>'
            . '<span class="aivis-toggle__text"><span class="aivis-toggle__title">%4$s</span>'
            . '<span class="aivis-toggle__help">%5$s</span></span>'
            . '</label></div>',
            esc_attr($id),
            esc_attr(self::name($key)),
            checked($checked, true, false),
            esc_html($label),
            esc_html($help),
        );
    }

    /**
     * A single-line input.
     *
     * @param  'text'|'email'|'url'  $type
     */
    public static function input(string $key, string $label, string $help, string $value, string $type = 'text'): void
    {
        $id = 'aivis-' . $key;

        printf(
            '<div class="aivis-field">'
            . '<label class="aivis-field__label" for="%1$s">%2$s</label>'
            . '<input type="%3$s" class="aivis-field__control" id="%1$s" name="%4$s" value="%5$s" aria-describedby="%1$s-help">'
            . '<p class="aivis-field__help" id="%1$s-help">%6$s</p>'
            . '</div>',
            esc_attr($id),
            esc_html($label),
            esc_attr($type),
            esc_attr(self::name($key)),
            esc_attr($value),
            esc_html($help),
        );
    }

    /**
     * A multi-line input.
     */
    public static function textarea(string $key, string $label, string $help, string $value, int $rows = 5, string $placeholder = ''): void
    {
        $id = 'aivis-' . $key;

        printf(
            '<div class="aivis-field">'
            . '<label class="aivis-field__label" for="%1$s">%2$s</label>'
            . '<textarea class="aivis-field__control aivis-field__control--area" id="%1$s" name="%3$s" rows="%4$s" '
            . 'placeholder="%5$s" aria-describedby="%1$s-help" spellcheck="false">%6$s</textarea>'
            . '<p class="aivis-field__help" id="%1$s-help">%7$s</p>'
            . '</div>',
            esc_attr($id),
            esc_html($label),
            esc_attr(self::name($key)),
            esc_attr((string) $rows),
            esc_attr($placeholder),
            esc_textarea($value),
            esc_html($help),
        );
    }

    /**
     * A bounded number input, rendered beside its unit.
     */
    public static function number(string $key, string $label, string $help, int $value, int $min, int $max): void
    {
        $id = 'aivis-' . $key;

        printf(
            '<div class="aivis-field aivis-field--narrow">'
            . '<label class="aivis-field__label" for="%1$s">%2$s</label>'
            . '<input type="number" class="aivis-field__control aivis-field__control--number" id="%1$s" '
            . 'name="%3$s" value="%4$s" min="%5$s" max="%6$s" step="1" aria-describedby="%1$s-help">'
            . '<p class="aivis-field__help" id="%1$s-help">%7$s</p>'
            . '</div>',
            esc_attr($id),
            esc_html($label),
            esc_attr(self::name($key)),
            esc_attr((string) $value),
            esc_attr((string) $min),
            esc_attr((string) $max),
            esc_html($help),
        );
    }

    /**
     * One selectable post type, drawn as a card rather than a bare checkbox.
     */
    public static function typeCard(string $key, string $value, string $label, string $count, bool $checked): void
    {
        $id = 'aivis-' . $key . '-' . $value;

        printf(
            '<div class="aivis-card-check">'
            . '<input type="checkbox" class="aivis-card-check__input" id="%1$s" name="%2$s" value="%3$s" %4$s>'
            . '<label class="aivis-card-check__label" for="%1$s">'
            . '<span class="aivis-card-check__name">%5$s</span>'
            . '<code class="aivis-card-check__slug">%3$s</code>'
            . '<span class="aivis-card-check__count">%6$s</span>'
            . '</label></div>',
            esc_attr($id),
            esc_attr(self::name($key, true)),
            esc_attr($value),
            checked($checked, true, false),
            esc_html($label),
            esc_html($count),
        );
    }
}
