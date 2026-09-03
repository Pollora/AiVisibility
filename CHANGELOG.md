# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.3.3

### Fixed

- The fallback notice 1.3.2 added shipped in English only: it reached the
  catalogues after that release was cut. All six locales carry it now.

## 1.3.2

### Fixed

- **Activating the plugin after a Composer install fataled the site.** The
  bootstrap required `vendor/autoload.php` unconditionally, and that directory
  exists only in the release zip: installed as a Composer package the file is
  absent, so activation ended in a fatal error and the site answered 500. The
  autoloader is now required only when it is there — a Composer install already
  has the package's PSR-4 mapping in the consuming project's autoloader. If
  neither source produced the classes the dashboard says so instead of fataling.

## 1.3.1

### Fixed

- `Tested up to` named WordPress 7.0 when 7.1 had shipped. The directory hides
  plugins that are not documented as tested against the current release, so this
  alone would have kept it out of search results.
- The WP-CLI file now refuses direct access. Every other file in the plugin only
  declares a class, and is inert if reached over HTTP; this one registers its
  command as it loads, so it needed the guard the others do not.

## 1.3.0

### Changed

- **The plugin is now named AmphiBee AI Visibility.** The directory rejects
  generic names — three listed plugins already use "AI Visibility", each of them
  behind a brand — and a name cannot be changed once approved.
- **The text domain is now `amphibee-ai-visibility`.** WordPress.org derives a
  plugin's slug from its name and names its language packs after that slug, so a
  domain that does not match it never receives a community translation. The
  shipped catalogues are renamed with it.

  Sites carrying their own translation of this plugin under the old
  `ai-visibility` domain need to rename that file to match. Nothing else is
  affected: the settings URL, the WP-CLI command, the ability identifiers, the
  hook prefix, the option keys and the uploads directory are all unchanged, so
  no generated file moves and no bookmark breaks.

## 1.2.0

### Added

- The plugin's mark now sits in the settings masthead, the same one the plugin
  directory shows, so wp-admin and the listing carry one identity.
- A `readme.txt`, which is what the WordPress.org directory reads to build a
  plugin's page — `README.md` is not consulted there.

### Changed

- The plugin is attributed to AmphiBee. `Author URI` pointed at a domain that
  does not resolve, and WordPress.org renders that field as a link on the
  listing.

### Fixed

- The icon carried no `viewBox`, so it could not scale: at any size other than
  its native 942px it rendered wrongly.
- Vulnerability reports no longer point at an unreachable mailbox. GitHub's
  private reporting, which works, is now the only channel named.

## 1.1.0

### Added

- French (fr_FR), German (de_DE), Italian (it_IT), Spanish (es_ES), Brazilian
  Portuguese (pt_BR) and Dutch (nl_NL) translations of every user-facing
  string, including the redesigned settings screen and the plural form used
  for post counts. WordPress selects the right one from the site's own
  language setting.

- **Rebuilt settings screen.** The four stacked `form-table` sections are now
  five panels behind a sidebar: Dashboard, Discovery, Content, AI crawlers,
  Identity. Each nav entry carries an icon, a label and a one-line description,
  and the whole screen sits in a single surface rather than floating on the
  admin's grey.
  - The panel is in the URL (`?tab=…`), so reloading, bookmarking, and the
    redirect WordPress performs after saving all come back to the panel you
    were on.
  - The switcher is a list of real links and every panel is in the DOM, so the
    screen still works with JavaScript disabled — and, more importantly, saving
    from one panel can never wipe the settings on the other four.
  - Switches are styled checkboxes, not scripted divs: they submit, they reach
    the tab order, and a screen reader announces them correctly.
  - Arrow keys, Home and End move between panels, per the ARIA tabs pattern.
- **Health checks on the dashboard.** Five conditions that silently break the
  plugin are now reported: plain permalinks (which make every endpoint 404), an
  unwritable uploads directory, "discourage search engines" (which withholds the
  robots.txt directives), missing generated files, and the availability of the
  Abilities API. None of them were visible from the settings form before.
- Each generated file is shown with its real size, its modification time in the
  site's own date format, a link, and a copy-URL button.
- No web font, no icon font, no sprite: the icons are inline SVG. An outbound
  request from wp-admin would contradict the plugin's own no-network rule.

- Quality pipeline: Pest test suite, PHPStan at level `max`, PHPCS (WordPress
  security, escaping, i18n and PHP compatibility rulesets), Laravel Pint, and a
  parallel syntax lint — all run on every push by GitHub Actions.
- End-to-end workflow that installs a real WordPress, activates the plugin and
  asserts each endpoint answers with the right status and content type, across
  the supported PHP and WordPress matrix.
- Release workflow producing an installable zip built from `git archive`, so no
  development file can reach a distributed build.
- `uninstall.php`: deleting the plugin now removes its options, its scheduled
  event, its per-post transients and its generated files — on every site of a
  multisite network.
- `Artifact` enum as the single description of the four generated files, and a
  `FileStore` that writes them atomically via a temporary file plus `rename()`.

### Fixed

- The site-description placeholder example read "A municipal opposition site
  covering local council decisions, budgets and public consultations." — a
  leftover from the plugin's own origin, not a generic example for a
  distributable plugin. Replaced with a neutral one, in every shipped language.
- **Password-protected posts were exposed through the Markdown endpoint.** The
  converter called `the_content` directly, bypassing the password check, and the
  result was cached in a transient shared by every visitor — so one reader
  holding the password published the content to everyone. Protected posts now
  answer 404 and are excluded from `llms.txt` and `llms-full.txt`.
- **Fenced code blocks were never produced.** The inline `<code>` rule ran before
  the `<pre><code>` rule and consumed its inner element, so every code block came
  out as inline backticks. `<pre>` without a `<code>` child is now handled too.
- **Hard line breaks were silently dropped.** `<br>` was converted to the two
  trailing spaces Markdown requires, and the cleanup pass then trimmed them off.
- **The `enable_llms_txt` setting did nothing.** The endpoint was registered
  unconditionally; unchecking the box had no effect.
- **Deactivating left the endpoints answering 301.** The deactivation hook called
  `flush_rewrite_rules()` while the plugin was still loaded for that request, so
  the rules it meant to remove were immediately re-registered. `/llms.txt` kept
  matching a rule whose query var no longer existed, and WordPress answered with
  a canonical redirect instead of a 404.
- **`/?format=md` returned 404 on a static front page.** `WP_Query` only
  substitutes `page_on_front` when the query carries nothing beyond `preview`,
  `page`, `paged` and `cpage`; `format` is one more, so the home request fell
  through to the blog index and never resolved to the page.
- `AiDirectives::addDirectives()` declared its second argument as `bool`, but
  `do_robots()` passes `get_option('blog_public')` — the string `"1"` or `"0"`.
  The call only worked because WordPress core does not declare `strict_types`.
- Failed writes are reported instead of ignored. `wp_upload_dir()` errors, a
  read-only uploads mount or a full disk now surface in WP-CLI, in the admin
  regeneration button and in the return value of `regenerateAll()`.
- Settings read from the database are normalised into a known shape, so a
  corrupt or partially written option row degrades to defaults rather than
  throwing a `TypeError` deep inside a generator.
- A filter callback returning something other than a string no longer breaks
  generation; the unfiltered value is used instead.
- Every admin field is escaped on output, and the regeneration script moved from
  an inline `<script>` block to a properly enqueued asset.
- `handleRegenerate()` checks the capability before the nonce, so an
  unprivileged user is told they may not do this rather than that their nonce
  expired.
- The Markdown `<link rel="alternate">` is no longer advertised for
  password-protected or noindex posts.
- Regex conversion steps degrade gracefully when PCRE hits its backtrack limit
  on pathological markup, instead of blanking the whole document.

## 1.0.0

### Added

- `llms.txt` and `llms-full.txt` endpoints following the llmstxt.org
  specification.
- Markdown representation of any post or page, via a `.md` suffix or a
  `?format=md` query parameter, advertised with a `Link` header and a
  `<link rel="alternate">` tag.
- AI crawler directives appended to the virtual `robots.txt`.
- AI discovery files at `/.well-known/ai.txt` and `/.well-known/identity.json`,
  with `/ai-discovery/` fallbacks for servers that block dotfile paths.
- WordPress Abilities API registration for MCP clients (WordPress 6.9+).
- Settings screen under Settings → AI Visibility.
- `wp ai-visibility generate` and `wp ai-visibility status` WP-CLI commands.
- Description and noindex detection for The SEO Framework, Yoast SEO, Rank Math
  and SEOPress.
