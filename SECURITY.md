# Security policy

## Supported versions

The latest minor release receives security fixes. Older ones do not.

| Version | Supported |
|---|---|
| 1.x | ✅ |

## Reporting a vulnerability

**Please do not open a public issue.**

Use GitHub's private reporting — [Security → Report a
vulnerability](https://github.com/Pollora/AiVisibility/security/advisories/new) —
or email `security@pollora.com`.

Useful in a report: the plugin version, the WordPress and PHP versions, what an
attacker gains, and the smallest sequence of steps that demonstrates it.

You can expect an acknowledgement within three working days and an assessment
within ten. If the report is valid, you will be credited in the advisory and the
changelog unless you would rather not be.

## Threat model

The plugin exists to publish. Its entire purpose is to make public content
easier to read, so "content is readable" is not a vulnerability — but
**publishing something that was not already public is**.

Specifically in scope:

- Content that is not publicly readable becoming reachable through any of the
  plugin's endpoints: password-protected posts, drafts, private posts, or posts
  an SEO plugin marked `noindex`.
- Cache poisoning: one visitor's response being served to another. The Markdown
  cache is keyed per post and shared, so anything that varies by visitor must
  never enter it.
- Privilege escalation through the settings screen, the AJAX regeneration
  endpoint, the WP-CLI commands or the registered abilities.
- Stored or reflected XSS through the settings screen.
- Anything that lets a request write outside `wp-content/uploads/ai-visibility`.

Out of scope:

- Published content appearing in the generated files. That is the feature.
- Denial of service through repeatedly requesting `/llms-full.txt` on a very
  large site. Put a cache in front of it.
- Findings that require an administrator account to already be compromised.

## What the code does about it

- Password-protected posts answer `404` at their `.md` URL, are excluded from
  the generated files, and are never advertised as having a Markdown alternate.
  They are also excluded at the query level, not only filtered afterwards.
- The Markdown transient is only ever populated from content that is public to
  everyone, so a shared cache cannot leak a privileged view.
- The AJAX regeneration endpoint checks the capability *before* the nonce, so an
  unprivileged user is told they may not do this rather than that their nonce
  expired.
- Every admin field is escaped at the point of output.
- Settings read from the database are normalised into a known shape, so a
  corrupted option row degrades to defaults rather than flowing on as an
  unexpected type.
- Writes are confined to one directory inside the uploads folder, are atomic,
  and report failure rather than swallowing it.

CI runs the WordPress security sniffs, Semgrep's PHP and security rulesets, and
`composer audit` on every push, plus weekly so that an advisory published after
the last commit still surfaces.
