#!/usr/bin/env bash
# Tests for verify-dist.sh against a minimal synthetic OHIF bundle.
set -euo pipefail

script="$(cd "$(dirname "$0")" && pwd)/verify-dist.sh"
root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT

make_dist() {
  local dist="$root/$1"
  mkdir -p "$dist/assets"
  cat >"$dist/index.html" <<'HTML'
<html><head><title>OHIF Viewer</title>
<link rel="icon" href="/ohif/assets/favicon.ico">
<script src="/ohif/app-config.js"></script>
<script defer="defer" src="/ohif/app.bundle.0123abcd.js"></script>
<link href="/ohif/app.bundle.css" rel="stylesheet">
</head></html>
HTML
  echo 'icon' >"$dist/assets/favicon.ico"
  echo 'window.config={routerBasename:"/ohif/",defaultDataSourceName:"dicomjson"};' >"$dist/app-config.js"
  echo 'boot();' >"$dist/app.bundle.0123abcd.js"
  echo 'body{}' >"$dist/app.bundle.css"
  echo "$dist"
}

expect_pass() {
  bash "$script" "$2" >/dev/null || { echo "FAIL: $1 should pass" >&2; exit 1; }
}

expect_fail() {
  if bash "$script" "$2" >/dev/null 2>&1; then
    echo "FAIL: $1 should be rejected" >&2
    exit 1
  fi
}

expect_pass 'a complete bundle' "$(make_dist ok)"

dist="$(make_dist missing-asset)"
rm "$dist/assets/favicon.ico"
expect_fail 'a referenced file that is missing' "$dist"

dist="$(make_dist empty-bundle)"
: >"$dist/app.bundle.0123abcd.js"
expect_fail 'an empty app bundle' "$dist"

dist="$(make_dist escape)"
sed -i.bak 's#/ohif/assets/favicon.ico#/ohif/../.env#' "$dist/index.html"
echo "outside the bundle" >"$root/.env"
expect_fail 'a reference escaping /ohif/' "$dist"

dist="$(make_dist outside)"
sed -i.bak 's#/ohif/app.bundle.css#/app.bundle.css#' "$dist/index.html"
expect_fail 'a reference outside /ohif/' "$dist"

dist="$(make_dist basename)"
echo 'window.config={routerBasename:null,defaultDataSourceName:"dicomjson"};' >"$dist/app-config.js"
expect_fail 'an unpatched router basename' "$dist"

dist="$(make_dist datasource)"
echo "window.config={routerBasename:'/ohif/',defaultDataSourceName:'ohif'};" >"$dist/app-config.js"
expect_fail 'the upstream demo data source' "$dist"

echo 'verify-dist tests passed.'
