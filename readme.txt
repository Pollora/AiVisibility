=== AI Visibility ===
Contributors: ogorzalka
Tags: llms-txt, ai, markdown, seo, robots-txt
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes llms.txt, a Markdown twin of every page, AI crawler directives and discovery files, so AI engines can read your site properly.

== Description ==

Your site already publishes HTML for browsers and a sitemap for search crawlers.
AI Visibility adds the formats the newer readers actually want: a structured
index at `/llms.txt`, a Markdown twin of every page, explicit crawler
permissions in `robots.txt`, and machine-readable identity files.

Install it, and it works. There is nothing to configure to get a usable result.

= What it publishes =

* `/llms.txt` — an index of the site following the [llmstxt.org](https://llmstxt.org) specification: title, description, and a linked list of pages with summaries.
* `/llms-full.txt` — every tracked page's full content, as one Markdown document.
* `/any-page.md` — the Markdown representation of a single page, also reachable as `/any-page/?format=md`.
* `/index.md` — the static front page, when the site has one.
* `/.well-known/ai.txt` — declared AI usage permissions, and pointers to the files above.
* `/.well-known/identity.json` — a schema.org `Organization` description of the site.
* `/robots.txt` — gains explicit `Allow` and `Disallow` blocks per AI crawler.

Every HTML page advertises its Markdown twin, both in the `<head>` and in the
HTTP headers, so a client that does not know the URL convention can still find
it.

Some nginx configurations block `/.well-known/` outright, through the default
`location ~ /\.` rule. The discovery files are therefore also served from
`/ai-discovery/ai.txt` and `/ai-discovery/identity.json`.

= What it never publishes =

Content that is not public stays that way. Drafts, password-protected posts and
anything an SEO plugin has marked `noindex` are excluded from `llms.txt` and
`llms-full.txt`, answer `404` at their `.md` URL, and are never advertised as
having a Markdown alternate.

= It does not phone home =

The plugin makes no outbound HTTP request. Not to a licence server, not to an
analytics endpoint, not for a web font or an icon in the admin — the settings
screen's icons are inline SVG for that reason. Nothing about your content
leaves your server.

= Works with your SEO plugin =

Descriptions and `noindex` flags are read from whichever of these is installed,
in this order: The SEO Framework, Yoast SEO, Rank Math, SEOPress. With none
installed, the excerpt is used, falling back to the first 30 words of the
content.

= Callable by AI clients, on WordPress 6.9 and newer =

Where the Abilities API is available, the plugin registers three abilities any
connected AI client can call: reading `llms.txt`, reading a site summary, and
triggering a regeneration. The last one requires `manage_options`; the other two
require `read`.

This is the plugin being read, not the plugin calling out. It consumes no AI
provider and needs no connector, no API key and no credit — everything else it
does works identically on WordPress 6.7.

= For developers =

Eight filters cover every generated file. Each one falls back to the unfiltered
value if a callback returns the wrong type, so a third-party mistake cannot take
the site down.

* `ai_visibility_llms_txt` — the complete `llms.txt`
* `ai_visibility_llms_full_txt` — the complete `llms-full.txt`
* `ai_visibility_ai_txt` — the complete `ai.txt`
* `ai_visibility_identity_json` — the identity array, before encoding
* `ai_visibility_markdown` — a page's complete Markdown
* `ai_visibility_markdown_content` — just the converted body
* `ai_visibility_markdown_meta` — the `Key: Value` metadata lines
* `ai_visibility_markdown_before_content` — Markdown inserted above the body

WP-CLI is supported: `wp ai-visibility generate all`, `generate <file>`,
`--dry-run`, and `wp ai-visibility status`. A failed write is an error with a
non-zero exit code, not a silent no-op.

Source, issues and the full hook reference with examples:
[github.com/Pollora/AiVisibility](https://github.com/Pollora/AiVisibility).

= Translations =

The admin screen and every user-facing message are translatable, and the plugin
ships with French, German, Italian, Spanish, Brazilian Portuguese and Dutch.
WordPress picks the right one from the site's own language setting; there is
nothing to configure. Untranslated locales fall back to English.

Developed and maintained by [AmphiBee](https://amphibee.fr).

== Installation ==

1. Install the plugin through `Plugins → Add New`, or upload the zip through `Plugins → Add New → Upload Plugin`.
2. Activate it.
3. Visit `Settings → AI Visibility`. The dashboard reports what has been generated and flags anything standing in the way.

Pretty permalinks are required — `Settings → Permalinks`, anything but "Plain".
With plain permalinks every endpoint returns a 404, and the dashboard says so.

Composer users can instead run `composer require pollora/ai-visibility`.

== Frequently Asked Questions ==

= Do I have to configure anything? =

No. The defaults publish posts and pages, allow the mainstream AI search
crawlers, and block the two best-known training-only ones. Every setting is
optional.

= /llms.txt returns a 404. What is wrong? =

Almost always plain permalinks, which leave WordPress without the rewrite rules
the endpoints need. Switch `Settings → Permalinks` to any other option. The
plugin's dashboard checks this for you, along with an unwritable uploads
directory and the "discourage search engines" setting.

= Does it expose private or protected content? =

No. Drafts, password-protected posts, and anything marked `noindex` by your SEO
plugin are excluded from every generated file, and their `.md` URL answers 404.

= Does it send my content to an AI provider? =

No. The plugin generates static files on your own server and makes no outbound
request of any kind. What AI crawlers then do with the public files is governed
by the `robots.txt` and `ai.txt` directives you control from the settings
screen.

= Will it slow my site down? =

No. Files are generated when a tracked post is saved — debounced by 60 seconds,
so a burst of edits costs one pass — and served from disk afterwards. Nothing is
generated on the fly during a visitor's request.

= Which post types are included? =

Posts and pages by default. Any public post type can be added from the settings
screen, with a per-type cap of 50 entries, adjustable up to 200.

= Can I change what goes into the generated files? =

Yes, through eight filters, all prefixed `ai_visibility_`. They are listed in
the Description above: one per generated file, plus three that shape a page's
Markdown.

= Why does it require PHP 8.3? =

The codebase uses typed, modern PHP throughout and is analysed at PHPStan's
strictest level. Supporting older runtimes would mean giving up the guarantees
that keep a plugin like this one out of your error log.

== Screenshots ==

1. The dashboard: every generated file with its size, its age and a copy-URL button, above the health checks for the five conditions that silently break the plugin.
2. Discovery: the five formats the site publishes, each a switch. Turning one off removes its endpoint entirely.
3. Content: the description that heads llms.txt, ai.txt and identity.json, which post types feed the files, and the per-type cap.
4. AI crawlers: one bot per line, allowed or blocked. Each list becomes a User-agent block in robots.txt.
5. Identity: the contact address and social profiles published as a schema.org Organization in identity.json.

== Changelog ==

= 1.1.0 =

* Rebuilt settings screen: five panels behind a sidebar — Dashboard, Discovery, Content, AI crawlers, Identity — in place of four stacked form tables. The panel is in the URL, so reloading, bookmarking and the post-save redirect all return to where you were.
* The switcher is real links and every panel is in the DOM, so the screen works without JavaScript, and saving one panel can never wipe the settings on the other four.
* Health checks on the dashboard: plain permalinks, an unwritable uploads directory, "discourage search engines", missing generated files, and Abilities API availability — none of which were visible before.
* Each generated file is now shown with its real size, its modification time in the site's own date format, a link, and a copy-URL button.
* Switches are styled checkboxes, not scripted divs: they submit, they reach the tab order, and screen readers announce them correctly. Arrow keys, Home and End move between panels, per the ARIA tabs pattern.
* French, German, Italian, Spanish, Brazilian Portuguese and Dutch translations of every user-facing string.

= 1.0.0 =

* `llms.txt` and `llms-full.txt` endpoints following the llmstxt.org specification.
* Markdown representation of any post or page, via a `.md` suffix or a `?format=md` parameter, advertised with a `Link` header and a `<link rel="alternate">` tag.
* AI crawler directives appended to the virtual `robots.txt`.
* AI discovery files at `/.well-known/ai.txt` and `/.well-known/identity.json`, with `/ai-discovery/` fallbacks for servers that block dotfile paths.
* Abilities API registration for AI clients.
* Settings screen under Settings → AI Visibility.
* `wp ai-visibility generate` and `wp ai-visibility status` WP-CLI commands.
* Description and noindex detection for The SEO Framework, Yoast SEO, Rank Math and SEOPress.

== Upgrade Notice ==

= 1.1.0 =
Rebuilt settings screen with health checks that surface the five conditions
which silently break the plugin, and six new translations. No change to the
generated files; no action needed after updating.
