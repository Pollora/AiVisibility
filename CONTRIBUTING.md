# Contributing

## Getting set up

```bash
git clone https://github.com/Pollora/AiVisibility.git
cd AiVisibility
composer install
composer qa
```

`composer qa` is exactly what CI runs. If it passes locally it passes there.

## The quality gate

Five tools, each answering a different question.

| Command | Question it answers |
|---|---|
| `composer lint` | Does every file parse? |
| `composer format:check` | Is it written the way the rest of the codebase is? |
| `composer sniff` | Is anything unescaped, unsanitised, untranslated or incompatible with PHP 8.3 / WordPress 6.7? |
| `composer analyse` | Do the types hold, at PHPStan's strictest level? |
| `composer test` | Does it do what it claims? |

`composer format` applies style fixes; `composer sniff:fix` applies the ones
PHPCS can make automatically.

### Code style

Laravel Pint, PSR-12, configured in `pint.json`. This is deliberately **not**
the WordPress Coding Standard: the codebase uses `declare(strict_types=1)`,
camelCase methods, final classes and constructor promotion, and WPCS would
require rewriting all of it into a style it does not otherwise follow.

What is kept from the WordPress standards is the part that matters for a plugin
running on somebody else's site: the security, escaping, i18n, deprecation and
version-compatibility sniffs. Those are in `phpcs.xml.dist`, along with a
comment for every deliberate exemption.

### Static analysis

PHPStan runs at `level: max` over `src/`, `cli/`, `ai-visibility.php` and
`uninstall.php`, with WordPress and WP-CLI stubs loaded. There is no baseline
and none should be added: a new error means either a real defect or a type that
deserves to be written down.

Tests are not analysed. Pest describes them as closures rebound onto a generated
test case, so every `$this->` in a test resolves to `PHPUnit\Framework\TestCase`
and reports as an undefined property. The suite proves itself by running.

## Tests

```bash
composer test                       # everything
vendor/bin/pest --filter=Markdown   # one file
composer test:coverage              # with coverage, enforcing the minimum
```

### Coverage

The threshold is 85%, a few points below what the suite actually reaches, so it
ratchets rather than tripping on a single new line.

It will not go much higher, and that is deliberate. The uncovered lines are
almost entirely the response code — `header()` then `echo` then `exit` — in the
three endpoint classes and the settings screen. `exit` cannot be intercepted, so
a unit test that reached those lines would end the test run. They are covered
instead by `tests/e2e/verify.sh`, which asserts the actual status codes,
headers and bodies over HTTP. Chasing the number by injecting a mockable
responder would buy a bigger percentage and no extra confidence.

The unit suite runs **without a WordPress installation**. Brain Monkey replaces
the core functions; `tests/Fixtures/wp-classes.php` provides minimal doubles for
the few core classes the plugin type-hints against. That keeps the suite fast
and forces the code to state its dependencies rather than reach for globals.

Two consequences worth knowing before writing a test:

- Brain Monkey leaves a mocked function **defined for the rest of the process**.
  A `function_exists()` guard therefore stays true in every test that runs after
  one mocks it. Anything the plugin probes for — an SEO plugin's facade, the
  Abilities API — must have its absent state declared in `tests/TestCase.php`,
  or test order becomes a source of failures.
- Tests run in random order by design. A test that only passes in a particular
  order is a broken test, not an ordering problem.

### End-to-end

What a unit test cannot see: whether the rewrite rules resolve, whether a
canonical redirect hijacks `/llms.txt`, whether the content type is right,
whether protected content stays protected. `tests/e2e/verify.sh` asserts all of
it against a real WordPress over HTTP, and the workflow runs it across the
supported PHP and WordPress matrix.

```bash
bash tests/e2e/verify.sh http://localhost:8080 wp --path=/path/to/wordpress
```

It expects a **fresh** install: it creates its own fixtures and asserts on
specific slugs.

## Adding a generated file

The four files are described once, in the `Artifact` enum. Add a case there and
the WP-CLI command, the admin preview, the regeneration cycle and the endpoint
routing all follow. There should be no second list anywhere.

## Changing behaviour

- Anything a user would notice belongs in `CHANGELOG.md` under `Unreleased`.
- User-facing strings go through `__()` with the `ai-visibility` text domain and
  are escaped at the point of output, never before.
- New hooks are prefixed `ai_visibility_` and go through `Support\Filters` so a
  third-party callback returning the wrong type cannot break the site.

## Releasing

1. Move the `Unreleased` entries under a new `## X.Y.Z` heading in `CHANGELOG.md`.
2. Bump `Version:` in the `ai-visibility.php` header **and** the `VERSION`
   constant below it. `DistributionTest` fails if they disagree, or if either
   disagrees with the changelog.
3. Bump `Stable tag:` in `readme.txt`, and add the entry to its own
   `== Changelog ==` — the directory reads that file, not `CHANGELOG.md`, and it
   serves whatever `Stable tag` names. Left behind, it keeps serving the
   previous release however new the tree in trunk is. `DistributionTest` checks
   it against the header, along with `Requires at least`.
4. Regenerate the translation template — its `Project-Id-Version` header
   carries the plugin version, so a bump alone puts it out of date and CI's
   drift check fails on a tree that `composer qa` calls clean:

   ```bash
   wp i18n make-pot . languages/ai-visibility.pot \
     --slug=ai-visibility --domain=ai-visibility \
     --exclude=vendor,tests,build,node_modules \
     --headers='{"Report-Msgid-Bugs-To":"https://github.com/Pollora/AiVisibility/issues","Last-Translator":"Pollora","Language-Team":"Pollora"}'
   ```

   The `.po` files are left alone: their `Project-Id-Version` records the
   version each translation was made against, and only a retranslation moves it.
5. Tag `vX.Y.Z` and push it.

The release workflow re-runs the whole gate on the tagged tree, refuses to build
if the tag does not match the plugin header, builds the zip from `git archive`
so no development file can slip in, verifies the result, and publishes it with
that version's changelog entry as the release notes.
