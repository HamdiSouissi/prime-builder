#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8091}"
ADMIN_EMAIL="${2:-admin@prime.test}"
ADMIN_PASS="${3:-AdminPass123!}"

COOKIE_JAR="$(mktemp)"
trap 'rm -f "$COOKIE_JAR"' EXIT

# 1. Fetch login page and extract _csrf_token
LOGIN_HTML="$(curl -fsS -c "$COOKIE_JAR" "$BASE_URL/admin/login")"
CSRF_TOKEN="$(printf '%s' "$LOGIN_HTML" | sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' | head -n 1)"

if [ -z "$CSRF_TOKEN" ]; then
  echo "Failed to extract _csrf_token from /admin/login"
  exit 1
fi

# 2. Login as admin
curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -d "_csrf_token=$CSRF_TOKEN" \
  -d "email=$ADMIN_EMAIL" \
  -d "password=$ADMIN_PASS" \
  -L "$BASE_URL/admin/login" >/dev/null

# 3. Check all plugin routes return HTTP 200
for path in \
  "/admin/prime-builder" \
  "/admin/prime-builder/submissions" \
  "/admin/prime-builder/kit" \
  "/admin/prime-builder/settings" \
  "/prime-builder/css/kit-smoke.css"
do
  code="$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "$BASE_URL$path")"
  echo "$path -> HTTP $code"
  if [ "$code" != "200" ]; then
    echo "Smoke test failed on $path (HTTP $code)"
    exit 1
  fi
done

echo "All smoke checks passed!"
