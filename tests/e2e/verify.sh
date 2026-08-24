#!/usr/bin/env bash
#
# End-to-end verification against a running WordPress with the plugin active.
#
# The unit suite proves the generators produce the right bytes. This proves the
# bytes actually reach a client: rewrite rules resolve, no canonical redirect
# hijacks the URL, the content type is right, and protected content stays
# protected. None of that is observable without a real WordPress.
#
# Usage: verify.sh <base-url> <wp-cli-command...>
#   verify.sh http://127.0.0.1:8080 wp --path=/tmp/wordpress

set -euo pipefail

BASE_URL="${1:?usage: verify.sh <base-url> <wp-cli-command...>}"
shift
WP=("$@")

PASS=0
FAIL=0

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

ok() {
    green "  ✓ $1"
    PASS=$((PASS + 1))
}

ko() {
    red "  ✗ $1"
    red "      $2"
    FAIL=$((FAIL + 1))
    if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
        echo "::error::$1 — $2"
    fi
}

# status <path> <expected-code> — no redirects followed, so a 301 is a failure.
status() {
    local path="$1" expected="$2"
    local actual
    actual=$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}${path}")

    if [[ "$actual" == "$expected" ]]; then
        ok "GET ${path} → ${expected}"
    else
        ko "GET ${path} → ${expected}" "got ${actual}"
    fi
}

# header <path> <header-name> <expected-substring>
header() {
    local path="$1" name="$2" expected="$3"
    local actual
    actual=$(curl -sI "${BASE_URL}${path}" | tr -d '\r' | grep -i "^${name}:" | head -1 || true)

    if [[ "$actual" == *"$expected"* ]]; then
        ok "${path} sends ${name}: ${expected}"
    else
        ko "${path} sends ${name}: ${expected}" "got '${actual:-<absent>}'"
    fi
}

# Fetch a body into a variable rather than piping it.
#
# `curl ... | grep -q` looks obvious and is a trap under `set -o pipefail`:
# grep exits the moment it matches, curl is killed by SIGPIPE and exits 23, and
# pipefail then reports the whole pipeline as failed — turning a successful
# match into a failed assertion, but only for documents large enough that curl
# is still writing when grep leaves. That race made this suite report phantom
# failures on the HTML pages and pass on the small text ones.
body() {
    curl -s "${BASE_URL}${1}"
}

# body_contains <path> <substring>
body_contains() {
    local path="$1" needle="$2" content
    content=$(body "$path")

    if [[ "$content" == *"$needle"* ]]; then
        ok "${path} contains '${needle}'"
    else
        ko "${path} contains '${needle}'" "not in the ${#content}-byte body: $(printf '%.200s' "$content")"
    fi
}

# body_lacks <path> <substring>
body_lacks() {
    local path="$1" needle="$2" content
    content=$(body "$path")

    if [[ "$content" == *"$needle"* ]]; then
        ko "${path} does not leak '${needle}'" 'found in the response body'
    else
        ok "${path} does not leak '${needle}'"
    fi
}

section() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ---------------------------------------------------------------------------
section 'Fixtures'
# ---------------------------------------------------------------------------

"${WP[@]}" post create \
    --post_type=post --post_status=publish --post_title='Visible Article' \
    --post_name='visible-article' \
    --post_content='<h2>A heading</h2><p>Some <strong>content</strong>.</p><ul><li>One</li></ul>' \
    --porcelain > /dev/null
ok 'created a published post'

"${WP[@]}" post create \
    --post_type=post --post_status=publish --post_title='Protected Article' \
    --post_name='protected-article' --post_password='hunter2' \
    --post_content='<p>CONFIDENTIAL-MARKER</p>' \
    --porcelain > /dev/null
ok 'created a password-protected post'

"${WP[@]}" post create \
    --post_type=post --post_status=draft --post_title='Draft Article' \
    --post_name='draft-article' --post_content='<p>DRAFT-MARKER</p>' \
    --porcelain > /dev/null
ok 'created a draft'

"${WP[@]}" ai-visibility generate all > /dev/null
ok 'wp ai-visibility generate all succeeded'

"${WP[@]}" ai-visibility status > /dev/null
ok 'wp ai-visibility status succeeded'

# ---------------------------------------------------------------------------
section 'llms.txt endpoints'
# ---------------------------------------------------------------------------

status '/llms.txt' 200
status '/llms-full.txt' 200
header '/llms.txt' 'content-type' 'text/plain'
header '/llms.txt' 'x-robots-tag' 'noindex'
body_contains '/llms.txt' '# '
body_contains '/llms.txt' 'Visible Article'
body_contains '/llms.txt' '.md)'
body_contains '/llms-full.txt' 'A heading'

# ---------------------------------------------------------------------------
section 'Discovery files'
# ---------------------------------------------------------------------------

status '/.well-known/ai.txt' 200
status '/.well-known/identity.json' 200
status '/ai-discovery/ai.txt' 200
status '/ai-discovery/identity.json' 200
header '/.well-known/identity.json' 'content-type' 'application/json'
body_contains '/.well-known/ai.txt' 'Name:'

if body '/.well-known/identity.json' > "${TMPDIR:-/tmp}/identity.json" \
    && python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "${TMPDIR:-/tmp}/identity.json"; then
    ok 'identity.json parses as JSON'
else
    ko 'identity.json parses as JSON' 'the body is not valid JSON'
fi

# ---------------------------------------------------------------------------
section 'Markdown endpoint'
# ---------------------------------------------------------------------------

status '/visible-article.md' 200
header '/visible-article.md' 'content-type' 'text/markdown'
body_contains '/visible-article.md' '# Visible Article'
body_contains '/visible-article.md' '## A heading'
body_contains '/visible-article.md' '**content**'
body_contains '/visible-article.md' '- One'
body_contains '/visible-article.md' 'URL: '

status '/visible-article/?format=md' 200
body_contains '/visible-article/?format=md' '# Visible Article'

# A blog index is not a document: there is nothing to serve as Markdown.
status '/?format=md' 404

# The HTML page must advertise its Markdown twin, in the head and the headers.
body_contains '/visible-article/' 'rel="alternate" type="text/markdown"'
header '/visible-article/' 'link' 'text/markdown'

# ---------------------------------------------------------------------------
section 'Static front page'
# ---------------------------------------------------------------------------

FRONT_ID=$("${WP[@]}" post create --post_type=page --post_status=publish \
    --post_title='Welcome' --post_name='welcome' \
    --post_content='<p>FRONT-PAGE-MARKER</p>' --porcelain)
"${WP[@]}" option update show_on_front page > /dev/null
"${WP[@]}" option update page_on_front "$FRONT_ID" > /dev/null
"${WP[@]}" ai-visibility generate all > /dev/null

status '/index.md' 200
body_contains '/index.md' 'FRONT-PAGE-MARKER'
body_contains '/llms.txt' '/index.md'
status '/?format=md' 200

# Back to a blog index for the remaining checks.
"${WP[@]}" option update show_on_front posts > /dev/null
"${WP[@]}" option delete page_on_front > /dev/null

# ---------------------------------------------------------------------------
section 'Content that must not be exposed'
# ---------------------------------------------------------------------------

status '/protected-article.md' 404
body_lacks '/protected-article.md' 'CONFIDENTIAL-MARKER'
body_lacks '/llms-full.txt' 'CONFIDENTIAL-MARKER'
body_lacks '/llms.txt' 'Protected Article'
body_lacks '/llms-full.txt' 'DRAFT-MARKER'
body_lacks '/llms.txt' 'Draft Article'
status '/no-such-post.md' 404

# The protected page itself must not advertise a Markdown alternate.
body_lacks '/protected-article/' 'text/markdown'

# ---------------------------------------------------------------------------
section 'No canonical redirect on file-like URLs'
# ---------------------------------------------------------------------------

for path in /llms.txt /llms-full.txt /visible-article.md /.well-known/ai.txt; do
    redirects=$(curl -s -o /dev/null -w '%{num_redirects}' -L "${BASE_URL}${path}")
    if [[ "$redirects" == '0' ]]; then
        ok "${path} is served without a redirect"
    else
        ko "${path} is served without a redirect" "followed ${redirects} redirect(s)"
    fi
done

# ---------------------------------------------------------------------------
section 'robots.txt'
# ---------------------------------------------------------------------------

body_contains '/robots.txt' 'User-agent: GPTBot'
body_contains '/robots.txt' 'User-agent: CCBot'
body_contains '/robots.txt' 'llms.txt'

# ---------------------------------------------------------------------------
section 'Regeneration and cache invalidation'
# ---------------------------------------------------------------------------

"${WP[@]}" post create \
    --post_type=post --post_status=publish --post_title='Late Article' \
    --post_name='late-article' --post_content='<p>Published after the first run.</p>' \
    --porcelain > /dev/null

"${WP[@]}" ai-visibility generate all > /dev/null
body_contains '/llms.txt' 'Late Article'

# Editing a post must invalidate its Markdown cache, not serve a stale body.
"${WP[@]}" post update "$("${WP[@]}" post list --name=visible-article --field=ID --post_type=post)" \
    --post_content='<p>REVISED-CONTENT</p>' > /dev/null
body_contains '/visible-article.md' 'REVISED-CONTENT'

# ---------------------------------------------------------------------------
section 'Deactivation leaves nothing behind'
# ---------------------------------------------------------------------------

"${WP[@]}" plugin deactivate ai-visibility > /dev/null

# Deactivating must not leave a rewrite rule whose query var no longer exists:
# WordPress would answer the orphaned rule with a canonical 301 rather than a
# 404. The deactivation hook drops the cached rules so the next request rebuilds
# them without this plugin.
status '/llms.txt' 404
status '/visible-article.md' 404
body_lacks '/robots.txt' 'GPTBot'

"${WP[@]}" plugin activate ai-visibility > /dev/null
"${WP[@]}" rewrite flush > /dev/null
status '/llms.txt' 200
status '/visible-article.md' 200

# ---------------------------------------------------------------------------
printf '\n'
if [[ "$FAIL" -gt 0 ]]; then
    red "${FAIL} check(s) failed, ${PASS} passed."
    exit 1
fi

green "All ${PASS} checks passed."
