# AI Visibility

Makes a WordPress site legible to AI engines — LLMs, AI search, and AI agents.

The site already publishes HTML for browsers and a sitemap for search crawlers.
This plugin adds the formats the newer readers actually want: a structured index
at `/llms.txt`, a Markdown twin of every page, explicit crawler permissions in
`robots.txt`, and machine-readable identity files under `/.well-known/`.

[![CI](https://github.com/Pollora/AiVisibility/actions/workflows/ci.yml/badge.svg)](https://github.com/Pollora/AiVisibility/actions/workflows/ci.yml)
[![End-to-end](https://github.com/Pollora/AiVisibility/actions/workflows/e2e.yml/badge.svg)](https://github.com/Pollora/AiVisibility/actions/workflows/e2e.yml)
[![Security](https://github.com/Pollora/AiVisibility/actions/workflows/security.yml/badge.svg)](https://github.com/Pollora/AiVisibility/actions/workflows/security.yml)

---

## What it publishes

| URL | What it is |
|---|---|
| `/llms.txt` | Index of the site following the [llmstxt.org](https://llmstxt.org) specification: title, description, and a linked list of pages with summaries |
| `/llms-full.txt` | Every tracked page's full content as one Markdown document |
| `/any-page.md` | The Markdown representation of a single page — also reachable as `/any-page/?format=md` |
| `/index.md` | The static front page, when there is one |
| `/.well-known/ai.txt` | Declared AI usage permissions and pointers to the files above |
| `/.well-known/identity.json` | schema.org `Organization` description of the site |
| `/robots.txt` | Gains explicit `Allow` and `Disallow` blocks per AI crawler |

`/.well-known/` paths are blocked outright by some nginx configurations (the
default `location ~ /\.` rule). The discovery files are therefore also served
from `/ai-discovery/ai.txt` and `/ai-discovery/identity.json`.

Every HTML page advertises its Markdown twin, both in the head and in the
headers, so a client that does not know the URL convention can still find it:

```html
<link rel="alternate" type="text/markdown" href="https://example.com/page.md">
```

```http
Link: <https://example.com/page.md>; rel="alternate"; type="text/markdown"
```

## What it never publishes

Content that is not public stays that way. Password-protected posts, drafts and
anything an SEO plugin has marked `noindex` are excluded from `llms.txt` and
`llms-full.txt`, answer `404` at their `.md` URL, and are not advertised as
having a Markdown alternate.

## Requirements

- PHP 8.3 or newer
- WordPress 6.7 or newer
- Pretty permalinks (`Settings → Permalinks`, anything but "Plain")

## Installation

Download the zip from [the latest release][releases] and install it through
`Plugins → Add New → Upload Plugin`, or with Composer:

```bash
composer require pollora/ai-visibility
```

Then visit `Settings → AI Visibility`.

[releases]: https://github.com/Pollora/AiVisibility/releases/latest

## Languages

The admin screen and every user-facing message are translatable. Shipped
translations: French (fr_FR), German (de_DE), Italian (it_IT), Spanish (es_ES),
Brazilian Portuguese (pt_BR), Dutch (nl_NL).
WordPress loads the right one automatically from the site's own language
setting; nothing to configure. Untranslated locales fall back to English.

## Configuration

Everything is optional; the defaults publish `post` and `page`, allow the
mainstream AI search crawlers, and block the two best-known training-only ones.

| Setting | Default | Notes |
|---|---|---|
| Post types | `post`, `page` | Any public post type can be added |
| Posts per type | 50 | Capped at 200 |
| Site description | empty | Falls back to the WordPress tagline |
| Allowed crawlers | GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Google-Extended, Amazonbot | |
| Blocked crawlers | CCBot, Bytespider | |

Files are regenerated automatically when a tracked post is saved, debounced by
60 seconds so a burst of edits costs one pass.

## SEO plugin integration

Descriptions and `noindex` flags are read from whichever of these is installed,
in this order: The SEO Framework, Yoast SEO, Rank Math, SEOPress. With none
installed, the excerpt is used, falling back to the first 30 words.

## WP-CLI

```bash
wp ai-visibility generate all              # regenerate every file
wp ai-visibility generate llms-txt         # just one
wp ai-visibility generate llms-txt --dry-run   # print it, write nothing
wp ai-visibility status                    # what exists, how big, how old
```

A failed write is an error with a non-zero exit code, not a silent no-op.

## MCP / Abilities API

On WordPress 6.9 and newer the plugin registers three abilities, which any MCP
client connected to the site can call:

| Ability | Capability required |
|---|---|
| `ai-visibility/get-llms-txt` | `read` |
| `ai-visibility/get-site-summary` | `read` |
| `ai-visibility/regenerate` | `manage_options` |

## Hooks

Every hook receives the value it should filter, and falls back to the unfiltered
value if a callback returns the wrong type.

| Filter | Filters |
|---|---|
| `ai_visibility_llms_txt` | The complete `llms.txt` |
| `ai_visibility_llms_full_txt` | The complete `llms-full.txt` |
| `ai_visibility_ai_txt` | The complete `ai.txt` |
| `ai_visibility_identity_json` | The identity array, before encoding |
| `ai_visibility_markdown` | A page's complete Markdown |
| `ai_visibility_markdown_content` | Just the converted body |
| `ai_visibility_markdown_meta` | `['Key' => 'Value']` metadata lines |
| `ai_visibility_markdown_before_content` | Markdown inserted above the body |

```php
// Add structured metadata to events, above the prose.
add_filter( 'ai_visibility_markdown_meta', function ( array $meta, WP_Post $post ): array {
    if ( $post->post_type === 'event' ) {
        $meta['Starts']   = get_post_meta( $post->ID, 'start_date', true );
        $meta['Location'] = get_post_meta( $post->ID, 'venue', true );
    }

    return $meta;
}, 10, 2 );
```

## Development

```bash
composer install
composer qa            # lint, style, PHPCS, PHPStan, tests — what CI runs
composer test          # tests alone
composer format        # apply the code style
```

The quality gate is described in [CONTRIBUTING.md](CONTRIBUTING.md). Reporting a
vulnerability: [SECURITY.md](SECURITY.md).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
