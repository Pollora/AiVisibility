# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

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
