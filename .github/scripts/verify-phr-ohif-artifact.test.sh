#!/usr/bin/env bash
set -euo pipefail
readonly script_dir="$(cd "$(dirname "$0")" && pwd)"
readonly fixture_root="$(mktemp -d)"
trap 'rm -rf "$fixture_root"' EXIT
export HOME="$fixture_root/home"
readonly app=phr-laravel
readonly release=aaaaaaaaaaaa-123-1
readonly commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
readonly owner="$release"
readonly php_bin="$fixture_root/php-zero"
control="$HOME/.deployments/$app"
stable="$HOME/$app"
mkdir -p "$control/shared/public/ohif" "$control/shared/storage/framework" "$control/deploy.lock" "$stable/public"
ln -s "$control/shared/public/ohif" "$stable/public/ohif"
ln -s "$control/shared/storage" "$stable/storage"
printf '%s\n' "release=$release" "commit=$commit" > "$stable/.deploy-release"
printf '%s\n' "$owner" > "$control/deploy.lock/owner"
printf '%s\n' '#!/bin/bash' 'exit 0' > "$php_bin"
chmod 700 "$php_bin"
expect_failure() { if "$@" >"$fixture_root/failure.log" 2>&1; then echo 'Unsafe artifact fixture accepted.' >&2; exit 1; fi; }
artifact="$HOME/.deployments/$app/shared/public/ohif"
printf '%s\n' '<html><title>OHIF Viewer</title><script src="/ohif/app.js"></script></html>' > "$artifact/index.html"
printf '%s\n' '/* synthetic OHIF bundle */' > "$artifact/app.js"
verify_artifact() {
    bash "$script_dir/verify-phr-ohif-artifact.sh" "$app" /usr/bin/php "$release" "$commit" "$owner"
}
verify_artifact
expect_failure bash "$script_dir/verify-phr-ohif-artifact.sh" "$app" "$php_bin" "$release" "$commit" "$owner"
expect_failure bash "$script_dir/verify-phr-ohif-artifact.sh" "$app" /usr/bin/php "$release" "$commit" foreign-owner
printf '%s\n' 'release=' >> "$HOME/$app/.deploy-release"
expect_failure verify_artifact
sed -i '$d' "$HOME/$app/.deploy-release"
printf '%s\n' 'commit=' >> "$HOME/$app/.deploy-release"
expect_failure verify_artifact
sed -i '$d' "$HOME/$app/.deploy-release"
mv "$artifact/app.js" "$artifact/missing.js"
expect_failure verify_artifact
ln -s "$artifact/missing.js" "$artifact/app.js"
expect_failure verify_artifact
unlink "$artifact/app.js"
mv "$artifact/missing.js" "$artifact/app.js"
printf '%s\n' '<html><title>Login</title></html>' > "$artifact/index.html"
expect_failure verify_artifact
printf '%s\n' '<html><title>OHIF</title><script src="https://hostile.invalid/app.js"></script></html>' > "$artifact/index.html"
expect_failure verify_artifact
printf '%s\n' '<html><title>OHIF</title><script src="../escaped.js"></script></html>' > "$artifact/index.html"
expect_failure verify_artifact
printf '%s\n' '<html><title>OHIF Viewer</title><script src="./app.js?v=synthetic"></script></html>' > "$artifact/index.html"
before_artifact=$(find "$artifact" -type f -exec sha256sum {} + | sort)
verify_artifact
[[ "$before_artifact" == "$(find "$artifact" -type f -exec sha256sum {} + | sort)" ]]
echo 'PHR read-only OHIF artifact tests passed.'
