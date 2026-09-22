#!/usr/bin/env bash
#
# Side-effect-free frontend build manifest preflight. NO network, NO SSH.
#
# This is the single definition of the Vite build-manifest contract, used in
# two places so the contract cannot drift between them:
#
#   1. A CI preflight step, run on the runner immediately after
#      actions/download-artifact populates public/build/ and BEFORE any
#      remote mutation (OHIF convergence, the shared cPanel deploy action).
#      A failure there is a runner / build-artifact problem, not evidence
#      that production is broken -- nothing has touched production yet.
#
#   2. verify-phr-deployment.sh, which sources this file and calls
#      verify_frontend_manifest again as its first check, before it makes
#      any HTTP or SSH call, so a broken local artifact still fails closed
#      with zero remote calls even from inside the post-deploy verifier.
#
# Coverage note (accurate framing, keep this in sync with callers' comments):
# this establishes that each isEntry:true manifest entry -- and the CSS it
# pulls in -- names a safe relative path, of an allowed type (.js/.css), is
# present on disk in the build tree being checked, and that the whole set
# stays under the bounded fan-out cap. It does NOT establish browser
# execution, the full lazy-loaded chunk graph, or that the rendered HTML
# references this build.
#
# verify_frontend_manifest MANIFEST_PATH BUILD_ROOT OUT_FILE [MAX_ASSETS]
#   MANIFEST_PATH  path to manifest.json
#   BUILD_ROOT     directory the manifest's "file"/"css" values are relative
#                  to, and that referenced files must exist under (normally
#                  the manifest's own directory, e.g. public/build)
#   OUT_FILE       written on success: one "relative/path<TAB>js|css" line
#                  per validated asset (entry files plus the CSS they pull in)
#   MAX_ASSETS     fan-out cap, default 12
#
# Returns non-zero and prints one reason to stderr on any violation. Honors
# PHR_VERIFY_PHP_BIN the same way verify-phr-deployment.sh does.

verify_frontend_manifest() {
    # Local vars are prefixed to avoid colliding with any `readonly` global of
    # the same short name in a caller that sources this file (bash refuses to
    # shadow a readonly global with a `local` of the same name).
    local _vfm_manifest_path="$1" _vfm_build_root="$2" _vfm_out_file="$3" _vfm_max_assets="${4:-12}"
    local _vfm_php_runner="${PHR_VERIFY_PHP_BIN:-php}"

    if [[ ! -f "$_vfm_manifest_path" ]]; then
        echo "Frontend build manifest not found at ${_vfm_manifest_path}." >&2
        return 1
    fi

    # shellcheck disable=SC2016 # The PHP program is intentionally a literal string.
    if ! PHR_MANIFEST_JSON="$_vfm_manifest_path" PHR_MANIFEST_ROOT="$_vfm_build_root" \
        PHR_MANIFEST_OUT="$_vfm_out_file" "$_vfm_php_runner" -r '
        $manifestPath = getenv("PHR_MANIFEST_JSON");
        $root = rtrim((string) getenv("PHR_MANIFEST_ROOT"), "/");

        $raw = file_get_contents($manifestPath);
        try {
            $data = json_decode((string) $raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Frontend build manifest is not valid JSON.\n");
            exit(1);
        }
        if (!is_array($data)) {
            fwrite(STDERR, "Frontend build manifest is not a JSON object.\n");
            exit(1);
        }

        $isSafeRelative = static function (string $path): bool {
            if ($path === "" || $path[0] === "/" || $path[0] === "\\") {
                return false; // absolute (POSIX or UNC/backslash-absolute)
            }
            if (preg_match("#^[A-Za-z]:[\\\\/]#", $path) === 1) {
                return false; // Windows drive-letter absolute path
            }
            foreach (explode("/", str_replace("\\", "/", $path)) as $part) {
                if ($part === "..") {
                    return false; // traversal
                }
            }
            return true;
        };

        $files = []; // relative path => true
        foreach ($data as $entry) {
            if (!is_array($entry) || ($entry["isEntry"] ?? false) !== true) {
                continue;
            }
            $file = $entry["file"] ?? null;
            if (!is_string($file) || $file === "") {
                fwrite(STDERR, "Frontend build manifest entry is missing its file.\n");
                exit(1);
            }
            $files[$file] = true;
            foreach ((array) ($entry["css"] ?? []) as $css) {
                if (!is_string($css) || $css === "") {
                    fwrite(STDERR, "Frontend build manifest entry has an invalid CSS reference.\n");
                    exit(1);
                }
                $files[$css] = true;
            }
        }
        if ($files === []) {
            fwrite(STDERR, "Frontend build manifest has no entry points to verify.\n");
            exit(1);
        }

        $lines = [];
        foreach (array_keys($files) as $file) {
            if (!$isSafeRelative($file)) {
                fwrite(STDERR, "Frontend build manifest references an unsafe path: {$file}\n");
                exit(1);
            }
            if (str_ends_with($file, ".js")) {
                $kind = "js";
            } elseif (str_ends_with($file, ".css")) {
                $kind = "css";
            } else {
                fwrite(STDERR, "Frontend build manifest entry has an unexpected asset type: {$file}\n");
                exit(1);
            }
            $localPath = $root."/".$file;
            if (!is_file($localPath)) {
                fwrite(STDERR, "Frontend build manifest references a file missing on disk: {$file}\n");
                exit(1);
            }
            $lines[] = $file."\t".$kind;
        }

        $handle = fopen(getenv("PHR_MANIFEST_OUT"), "w");
        foreach ($lines as $line) {
            fwrite($handle, $line."\n");
        }
        fclose($handle);
    '; then
        return 1
    fi

    # Bounded on purpose: this checks only the manifest's entry points
    # (isEntry: true) and the CSS each entry pulls in, never the full chunk
    # graph (vendor/ui-core/imaging splits, etc.). That is a handful of
    # hashed files, not the dozens a full-site crawl would touch. The cap is
    # a sanity backstop against that set growing unboundedly if the Vite
    # config changes.
    local _vfm_asset_count
    _vfm_asset_count="$(wc -l <"$_vfm_out_file" | tr -d ' ')"
    if (( _vfm_asset_count > _vfm_max_assets )); then
        echo "Frontend build manifest has ${_vfm_asset_count} entry-point assets, exceeding the bounded check limit of ${_vfm_max_assets}." >&2
        return 1
    fi
}

# Allow standalone execution as a CI preflight step, e.g.:
#   PHR_MANIFEST_PATH=public/build/manifest.json \
#   PHR_MANIFEST_BUILD_ROOT=public/build \
#     bash .github/scripts/verify-frontend-manifest.sh
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    set -euo pipefail
    : "${PHR_MANIFEST_PATH:?PHR_MANIFEST_PATH is required}"
    : "${PHR_MANIFEST_BUILD_ROOT:?PHR_MANIFEST_BUILD_ROOT is required}"
    out_file="${PHR_MANIFEST_OUT:-$(mktemp)}"
    trap '[[ -n "${PHR_MANIFEST_OUT:-}" ]] || rm -f "$out_file"' EXIT
    verify_frontend_manifest "$PHR_MANIFEST_PATH" "$PHR_MANIFEST_BUILD_ROOT" "$out_file" "${PHR_MANIFEST_MAX_ASSETS:-12}"
    asset_count="$(wc -l <"$out_file" | tr -d ' ')"
    echo "Frontend build manifest preflight passed: ${asset_count} entry-point asset(s) validated."
fi
