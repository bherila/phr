#!/usr/bin/env bash
# Check a built OHIF bundle before ohif-dist.yml deploys it to public/ohif/.
#
# Fails when:
#   - index.html is missing or is not the OHIF entry page;
#   - any src/href in index.html points outside /ohif/, escapes it with `..`,
#     or names a file that is missing or empty in the bundle;
#   - app-config.js does not carry the two settings patch-config.mjs applies
#     (router basename /ohif/, default data source dicomjson).
#
# Usage: verify-dist.sh <dist-dir>
set -euo pipefail

dist="${1:?usage: verify-dist.sh <dist-dir>}"
fail() { echo "verify-dist: $*" >&2; exit 1; }

index="$dist/index.html"
[ -s "$index" ] || fail "missing or empty index.html"
grep -Eqi '<title>[^<]*OHIF[^<]*</title>' "$index" || fail "index.html is not the OHIF entry page"

refs="$( (grep -oE '(src|href)="[^"]*"' "$index" || true) | sed -E 's/^(src|href)="([^"]*)"$/\2/')"
[ -n "$refs" ] || fail "index.html references no files"
grep -q '^/ohif/app\.bundle\.[0-9a-f]*\.js$' <<<"$refs" || fail "index.html does not load the app bundle"

while read -r ref; do
  path="${ref%%[?#]*}"
  case "$path" in
    /ohif/*) path="${path#/ohif/}" ;;
    *) fail "reference outside /ohif/: $ref" ;;
  esac
  case "/$path/" in
    */../*) fail "reference escapes /ohif/: $ref" ;;
  esac
  [ -s "$dist/$path" ] || fail "referenced file missing or empty: $ref"
done <<<"$refs"

config="$dist/app-config.js"
[ -s "$config" ] || fail "missing app-config.js"
grep -Eq "routerBasename: *[\"']/ohif/[\"']" "$config" || fail "app-config.js routerBasename is not /ohif/"
grep -Eq "defaultDataSourceName: *[\"']dicomjson[\"']" "$config" || fail "app-config.js default data source is not dicomjson"

echo "verify-dist: $(wc -l <<<"$refs" | tr -d ' ') references in index.html resolve inside the bundle; config patched."
