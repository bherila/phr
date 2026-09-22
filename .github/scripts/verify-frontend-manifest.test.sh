#!/usr/bin/env bash
#
# Unit tests for the side-effect-free frontend build manifest preflight
# helper. These exercise verify_frontend_manifest() directly against local
# fixtures only -- no HTTP, no SSH -- and additionally prove that claim by
# shadowing ssh/curl/rsync with canaries that fail the test the moment any of
# them is invoked.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly helper="$script_dir/verify-frontend-manifest.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

# --- Canaries: verify_frontend_manifest must never shell out to any of
# these. Each logs its invocation and exits non-zero so a stray call both
# fails loudly and leaves evidence.
canary_bin="$test_root/canary-bin"
canary_log="$test_root/canary.log"
mkdir -p "$canary_bin"
for tool in ssh curl rsync; do
    cat >"$canary_bin/$tool" <<SCRIPT
#!/usr/bin/env bash
printf '%s %s\n' "$tool" "\$*" >>"$canary_log"
exit 99
SCRIPT
    chmod +x "$canary_bin/$tool"
done
export PATH="$canary_bin:$PATH"

assert_no_remote_calls() {
    if [[ -s "$canary_log" ]]; then
        echo "Expected zero ssh/curl/rsync invocations, but got:" >&2
        cat "$canary_log" >&2
        exit 1
    fi
}

# shellcheck source=./verify-frontend-manifest.sh
source "$helper"

build="$test_root/build"
mkdir -p "$build/assets"

write_manifest() {
    printf '%s' "$1" >"$test_root/manifest.json"
}

run_helper() {
    local out="$test_root/out.tsv"
    rm -f "$out"
    HELPER_OUT="$out"
    HELPER_STDERR="$(verify_frontend_manifest "$test_root/manifest.json" "$build" "$out" 12 2>&1 1>/dev/null)" && HELPER_STATUS=0 || HELPER_STATUS=$?
}

expect_failure() {
    local case_name="$1" expected_substring="$2"
    run_helper
    assert_no_remote_calls
    if [[ "$HELPER_STATUS" == 0 ]]; then
        echo "[$case_name] expected failure but verify_frontend_manifest succeeded." >&2
        exit 1
    fi
    if [[ "$HELPER_STDERR" != *"$expected_substring"* ]]; then
        echo "[$case_name] unexpected message: $HELPER_STDERR" >&2
        exit 1
    fi
    echo "[$case_name] observed: $HELPER_STDERR"
}

# 1. Missing manifest.
rm -f "$test_root/manifest.json"
expect_failure 'missing manifest' 'not found at'

# 2. Malformed JSON.
write_manifest '{not valid json'
expect_failure 'malformed JSON' 'not valid JSON'

# 3. Empty entry set (no isEntry:true anywhere).
write_manifest '{"resources/js/app.tsx":{"file":"assets/app-abc.js","isEntry":false}}'
expect_failure 'empty entry set' 'no entry points'

# 4. Missing local entry file (manifest references a JS file that was never
# written into the build tree).
write_manifest '{"resources/js/app.tsx":{"file":"assets/app-missing.js","isEntry":true}}'
expect_failure 'missing local entry file' 'missing on disk'

# 5. Missing local CSS (the JS entry file exists, but the CSS it pulls in
# does not).
: >"$build/assets/app-abc.js"
write_manifest '{"resources/js/app.tsx":{"file":"assets/app-abc.js","isEntry":true,"css":["assets/app-missing.css"]}}'
expect_failure 'missing local CSS' 'missing on disk'
rm -f "$build/assets/app-abc.js"

# 6. Absolute path.
write_manifest '{"resources/js/app.tsx":{"file":"/etc/passwd","isEntry":true}}'
expect_failure 'absolute path' 'unsafe path'

# 7. ".." traversal.
write_manifest '{"resources/js/app.tsx":{"file":"../../etc/passwd.js","isEntry":true}}'
expect_failure '.. traversal' 'unsafe path'

# 8. Disallowed asset type.
: >"$build/assets/app-abc.map"
write_manifest '{"resources/js/app.tsx":{"file":"assets/app-abc.map","isEntry":true}}'
expect_failure 'disallowed extension' 'unexpected asset type'
rm -f "$build/assets/app-abc.map"

# 9. Fan-out cap exceeded (13 entries against the default cap of 12).
{
    printf '{'
    for i in $(seq 1 13); do
        [[ "$i" == 1 ]] || printf ','
        printf '"e%s.tsx":{"file":"assets/e%s.js","isEntry":true}' "$i" "$i"
        : >"$build/assets/e$i.js"
    done
    printf '}'
} >"$test_root/manifest.json"
expect_failure 'cap exceeded' 'exceeding the bounded check limit'
rm -f "$build"/assets/e*.js

# 10. Success case: a real entry JS file, an entry CSS-only file, and CSS
# pulled in by the JS entry, all present on disk.
: >"$build/assets/app-abc123.js"
: >"$build/assets/app-def456.css"
: >"$build/assets/pages-ghi789.css"
write_manifest '{
    "resources/css/app.css": {"file": "assets/app-def456.css", "isEntry": true},
    "resources/js/pages.tsx": {"file": "assets/app-abc123.js", "isEntry": true, "css": ["assets/pages-ghi789.css"]},
    "_shared-vendor.js": {"file": "assets/vendor-xyz.js", "isEntry": false}
}'
out="$test_root/out-success.tsv"
if ! verify_frontend_manifest "$test_root/manifest.json" "$build" "$out" 12; then
    echo '[success case] expected verify_frontend_manifest to succeed.' >&2
    exit 1
fi
assert_no_remote_calls
asset_count="$(wc -l <"$out" | tr -d ' ')"
[[ "$asset_count" == 3 ]] || { echo "[success case] expected 3 validated assets, got $asset_count." >&2; exit 1; }
grep -Fq $'assets/app-def456.css\tcss' "$out" || { echo '[success case] missing CSS entry in output.' >&2; exit 1; }
grep -Fq $'assets/app-abc123.js\tjs' "$out" || { echo '[success case] missing JS entry in output.' >&2; exit 1; }
grep -Fq $'assets/pages-ghi789.css\tcss' "$out" || { echo '[success case] missing entry CSS pull-in in output.' >&2; exit 1; }
grep -Fq 'vendor-xyz' "$out" && { echo '[success case] non-entry chunk leaked into output.' >&2; exit 1; }
echo '[success case] validated 3 assets as expected'

# 11. Standalone CLI mode: required-env enforcement and a passing run.
if PHR_MANIFEST_BUILD_ROOT="$build" bash "$helper" >/dev/null 2>&1; then
    echo 'Expected standalone mode to fail without PHR_MANIFEST_PATH.' >&2
    exit 1
fi
if PHR_MANIFEST_PATH="$test_root/manifest.json" bash "$helper" >/dev/null 2>&1; then
    echo 'Expected standalone mode to fail without PHR_MANIFEST_BUILD_ROOT.' >&2
    exit 1
fi
cli_out="$test_root/cli-out.tsv"
PHR_MANIFEST_PATH="$test_root/manifest.json" PHR_MANIFEST_BUILD_ROOT="$build" \
    PHR_MANIFEST_OUT="$cli_out" bash "$helper" >/dev/null
[[ -s "$cli_out" ]] || { echo 'Expected standalone mode to write its output file.' >&2; exit 1; }
assert_no_remote_calls

echo 'verify frontend manifest tests passed'
