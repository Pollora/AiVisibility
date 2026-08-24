<?php

declare(strict_types=1);

namespace Pollora\AiVisibility\Generator;

use Pollora\AiVisibility\Plugin;
use Pollora\AiVisibility\Support\Filters;

/**
 * Generates the /.well-known/identity.json structured site identity.
 */
final class IdentityJsonGenerator
{
    public function generate(): string
    {
        $settings = Plugin::settings();

        $identity = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            'name'        => get_bloginfo('name'),
            'url'         => home_url('/'),
            'description' => trim($settings['site_description']) ?: get_bloginfo('description'),
            'language'    => get_bloginfo('language'),
        ];

        // Contact email
        $email = $settings['identity_email'];
        if ($email !== '') {
            $identity['email'] = $email;
        }

        // Social links
        $socials = $settings['identity_socials'];
        if ($socials !== []) {
            $identity['sameAs'] = array_values(array_filter($socials));
        }

        // AI discovery pointers
        $siteUrl = home_url('/');
        $identity['additionalProperty'] = [
            [
                '@type' => 'PropertyValue',
                'name'  => 'llms-txt',
                'value' => $siteUrl . 'llms.txt',
            ],
            [
                '@type' => 'PropertyValue',
                'name'  => 'llms-full-txt',
                'value' => $siteUrl . 'llms-full.txt',
            ],
        ];

        $json = wp_json_encode(
            Filters::array('ai_visibility_identity_json', $identity),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        return is_string($json) ? $json : '{}';
    }
}
