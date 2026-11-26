#!/usr/bin/env bash
# Real HTTP smoke test against an installed CMS and a published builder page.
set -euo pipefail

base=${SMOKE_BASE_URL:-http://127.0.0.1:8080}
id=${SMOKE_ENTRY_ID:?SMOKE_ENTRY_ID is required}
email=${SMOKE_ADMIN_EMAIL:-builder@prime.test}
password=${SMOKE_ADMIN_PASSWORD:-Cascade-Corail-900!Orbit}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

get_200() {
  local path=$1 output=$2 code
  code=$(curl --silent --show-error --retry 15 --retry-delay 1 --retry-connrefused \
    --cookie "$tmp/cookies" --cookie-jar "$tmp/cookies" \
    --output "$output" --write-out '%{http_code}' "$base$path")
  if [[ $code != 200 ]]; then
    printf 'FAIL GET %s: HTTP %s (expected 200)\n' "$path" "$code" >&2
    head -c 1000 "$output" >&2
    exit 1
  fi
  printf 'OK GET %s: 200\n' "$path"
}

get_200 /admin/login "$tmp/login.html"
csrf=$(sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' "$tmp/login.html" | head -n 1)
if [[ -z $csrf ]]; then echo 'FAIL missing login CSRF token' >&2; exit 1; fi
code=$(curl --silent --show-error --cookie "$tmp/cookies" --cookie-jar "$tmp/cookies" \
  --output "$tmp/post.html" --write-out '%{http_code}' \
  --data-urlencode "_csrf_token=$csrf" --data-urlencode "email=$email" \
  --data-urlencode "password=$password" "$base/admin/login")
if [[ $code != 302 ]]; then printf 'FAIL POST /admin/login: HTTP %s (expected 302)\n' "$code" >&2; exit 1; fi
printf 'OK POST /admin/login: 302\n'
get_200 /admin/prime-builder "$tmp/dashboard.html"
get_200 /admin/prime-builder/settings "$tmp/settings.html"
get_200 /admin/prime-builder/kit "$tmp/kit.html"
get_200 /admin/prime-builder/theme "$tmp/theme.html"
get_200 /admin/prime-builder/submissions "$tmp/submissions.html"
get_200 "/admin/prime-builder/edit/$id" "$tmp/editor.html"
get_200 "/admin/content/page/$id/edit" "$tmp/core-editor.html"
get_200 /smoke-prime-builder "$tmp/public.html"
grep -Fq 'Page de test Prime Builder' "$tmp/public.html"
css=$(grep -oE '/prime-builder/css/[0-9]+-[a-f0-9]{16}\.css' "$tmp/public.html" | head -n 1)
if [[ -z $css ]]; then echo 'FAIL missing document stylesheet' >&2; exit 1; fi
get_200 "$css" "$tmp/document.css"
grep -Fq 'color:#123456' "$tmp/document.css"
printf 'Smoke: all routes, public content and document CSS passed.\n'
