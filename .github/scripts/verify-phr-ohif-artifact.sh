#!/usr/bin/env bash
# Read-only bundle proof streamed from the reviewed runner; never stored/executed
# from a candidate or used to bootstrap Laravel, mint tokens, or change runtime.
set -euo pipefail
[[ $# == 5 && $1 == phr-laravel && $2 == /* && -x $2 ]] || exit 2
php_bin=$2
release=$3
commit=$4
owner=$5
[[ "$release" =~ ^[A-Za-z0-9._-]+$ && "$commit" =~ ^[0-9a-f]{40}$ && "$owner" =~ ^[A-Za-z0-9._-]+$ ]] || exit 2
proof=$(/usr/bin/timeout --foreground --signal=TERM --kill-after=10s 30s "$php_bin" \
    -d memory_limit=1G -d display_errors=0 -d log_errors=0 /dev/stdin "$HOME" "$release" "$commit" "$owner" 2>/dev/null <<'PHP'
<?php
try {
    [$unused, $home, $release, $commit, $owner] = $argv;
    $stable = $home.'/phr-laravel';
    $control = $home.'/.deployments/phr-laravel';
    $root = $control.'/shared/public/ohif';
    foreach ([$home.'/.deployments', $control, $control.'/shared', $control.'/shared/public', $root, $stable, $control.'/deploy.lock'] as $directory) {
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) { exit(1); }
    }
    foreach ([$stable.'/.deploy-release', $control.'/deploy.lock/owner'] as $file) {
        if (!is_file($file) || is_link($file) || realpath($file) !== $file) { exit(1); }
    }
    $metadata = file_get_contents($stable.'/.deploy-release');
    preg_match_all('/^release=(.+)$/m', $metadata, $releases);
    preg_match_all('/^commit=(.+)$/m', $metadata, $commits);
    if (preg_match_all('/^release=/m', $metadata) !== 1 || preg_match_all('/^commit=/m', $metadata) !== 1
        || $releases[1] !== [$release] || $commits[1] !== [$commit]
        || trim(file_get_contents($control.'/deploy.lock/owner')) !== $owner
        || !is_link($stable.'/public/ohif') || realpath($stable.'/public/ohif') !== $root
        || !is_link($stable.'/storage') || realpath($stable.'/storage') !== $control.'/shared/storage'
        || file_exists($stable.'/storage/framework/down') || is_link($stable.'/storage/framework/down')) { exit(1); }
    $index = $root.'/index.html';
    if (!is_file($index) || is_link($index) || !is_readable($index) || realpath($index) !== $index) {
        exit(1);
    }
    $html = file_get_contents($index);
    if (!is_string($html) || !preg_match('/<title\b[^>]*>[^<]*OHIF[^<]*<\/title>/i', $html)) {
        exit(1);
    }
    preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\x27]([^"\x27]+)["\x27]/i', $html, $matches);
    if ($matches[1] === []) { exit(1); }
    foreach ($matches[1] as $source) {
        $path = preg_replace('/[?#].*$/', '', $source);
        if (str_starts_with($path, '/ohif/')) { $path = substr($path, 6); }
        while (str_starts_with($path, './')) { $path = substr($path, 2); }
        if (!preg_match('/^[A-Za-z0-9_.\/-]+\.js$/', $path) || str_starts_with($path, '/')
            || in_array('..', explode('/', $path), true)) { exit(1); }
        $file = $root.'/'.$path;
        if (!is_file($file) || !is_readable($file) || filesize($file) < 1 || realpath($file) !== $file) { exit(1); }
    }
    echo 'ohif_artifact=ok';
} catch (Throwable) { exit(1); }
PHP
) || { echo 'Read-only OHIF artifact proof failed; details redacted.' >&2; exit 1; }
[[ "$proof" == ohif_artifact=ok ]] || exit 1
echo 'Shared OHIF entrypoint and its local JavaScript artifacts proved read-only.'
