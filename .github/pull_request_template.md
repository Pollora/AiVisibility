## What changed

<!-- One or two sentences. What behaviour is different after this PR? -->

## Why

<!-- The problem this solves. Link an issue if there is one. -->

## Checklist

- [ ] `composer qa` passes locally (lint, style, PHPCS, PHPStan, tests)
- [ ] New behaviour is covered by a test that fails without the change
- [ ] `CHANGELOG.md` records anything a user would notice
- [ ] User-facing strings go through `__()` with the `ai-visibility` text domain
      and are escaped on output
- [ ] `Version:` in `ai-visibility.php` bumped if this is a release
