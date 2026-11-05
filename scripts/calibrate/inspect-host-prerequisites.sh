#!/usr/bin/env bash
# Read only fixed runtime prerequisites; never inspect app paths or environment values.
set -euo pipefail
[[ $# == 1 && $1 =~ ^/[A-Za-z0-9/._-]+$ ]] || exit 2
php_binary=$1
export PATH=/usr/bin:/bin
php_version=unknown
legacy_roots=null
legacy_window_matches=null
refuse() {
    printf '{"stage":"prerequisites","status":"refused","code":"%s","php_version":"%s","legacy_roots":%s,"legacy_window_matches":%s}\n' "$1" "$php_version" "$legacy_roots" "$legacy_window_matches"
    exit 1
}
for required in env timeout realpath stat id sha256sum cut rsync find mkdir cat unlink rmdir; do
    command -v "$required" >/dev/null 2>&1 || refuse "command_missing_$required"
done
[[ -x "$php_binary" ]] || refuse php_binary_missing
php_probe=$(timeout --kill-after=1s 5s "$php_binary" -n -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,".",PHP_RELEASE_VERSION;' 2>/dev/null) || refuse php_version_failed
[[ "$php_probe" =~ ^8\.5\.[0-9]{1,2}$ ]] || refuse php_version_invalid
php_version=$php_probe
# Audit only canonical private roots with the OLD fixed sentinel; report counts,
# never paths or contents. Existence never authorizes legacy-root cleanup.
legacy=$(timeout --kill-after=1s 5s env -i PATH=/usr/bin:/bin "$php_binary" -n /dev/stdin "$(id -u)" 2>/dev/null <<'PHP'
<?php
$matches = glob('/tmp/phr-calibration.??????????', GLOB_ONLYDIR);
if ($matches === false || count($matches) > 1000) { exit(1); }
$count = 0;
$window = 0;
foreach ($matches as $root) {
    if (!preg_match('#^/tmp/phr-calibration\.[A-Za-z0-9]{10}$#D', $root)
        || is_link($root) || realpath($root) !== $root
        || fileowner($root) !== (int) $argv[1] || (fileperms($root) & 0777) !== 0700) { continue; }
    $marker = $root.'/.owned-synthetic-calibration';
    if (!is_file($marker) || is_link($marker) || filesize($marker) > 64
        || trim((string) file_get_contents($marker)) !== 'phr-host-calibration-v1') { continue; }
    $count++;
    $created = filectime($root);
    if ($created >= strtotime('2026-11-05 09:37:55 UTC') && $created < strtotime('2026-11-05 09:37:58 UTC')) { $window++; }
}
echo $count, ':', $window;
PHP
) || refuse legacy_scan_failed
[[ "$legacy" =~ ^([0-9]{1,4}):([0-9]{1,4})$ ]] || refuse legacy_scan_invalid
legacy_roots=${BASH_REMATCH[1]}
legacy_window_matches=${BASH_REMATCH[2]}
[[ ! -L /dev/null && -c /dev/null && $(realpath /dev/null) == /dev/null \
    && $(stat -c '%t:%T' /dev/null) == 1:3 ]] || refuse null_device_invalid
printf '{"stage":"prerequisites","status":"ready","code":"ready","php_version":"%s","legacy_roots":%s,"legacy_window_matches":%s}\n' "$php_version" "$legacy_roots" "$legacy_window_matches"
