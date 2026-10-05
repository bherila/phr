#!/usr/bin/env bash
# One-time SSH authorization only. Never bootstrap Laravel or change application state.
set -euo pipefail
[[ $# == 6 && $1 =~ ^/[A-Za-z0-9/._-]+$ && -x $1 \
    && $2 =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ && $3 =~ ^[a-f0-9]{40}$ \
    && $5 =~ ^[1-9][0-9]{0,15}$ && $6 =~ ^[1-9][0-9]{0,5}$ ]] || exit 2
ulimit -f 1024
timeout --kill-after=2s 30s "$1" -d memory_limit=1G -d display_errors=0 -d log_errors=0 /dev/stdin \
    "$HOME" "$2" "$3" "$4" "$5" "$6" <<'PHP'
<?php
try {
    [, $home, $release, $commit, $publicKey, $run, $attempt] = $argv;
    if (!function_exists('posix_geteuid') || strlen($publicKey) > 256
        || preg_match('/\Assh-ed25519 ([A-Za-z0-9+\/]{68})(?: [A-Za-z0-9@._-]{1,128})?\z/', $publicKey, $match) !== 1) { throw new RuntimeException(); }
    $blob = base64_decode($match[1], true);
    if ($blob === false || strlen($blob) !== 51 || substr($blob, 0, 19) !== pack('N', 11).'ssh-ed25519'.pack('N', 32)) { throw new RuntimeException(); }
    $key = $match[1];
    $uid = posix_geteuid();
    $realDirectory = static function (string $path) use ($uid): void {
        clearstatcache(true, $path);
        if (!is_dir($path) || is_link($path) || realpath($path) !== $path || fileowner($path) !== $uid) { throw new RuntimeException(); }
    };
    $regular = static function (string $path) use ($uid): void {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || realpath($path) !== $path || fileowner($path) !== $uid || filesize($path) > 1048576) { throw new RuntimeException(); }
    };
    $stable = $home.'/phr-laravel';
    $control = $home.'/.deployments/phr-laravel';
    $storage = $control.'/shared/storage';
    $assertState = static function () use ($home, $stable, $control, $storage, $release, $commit, $realDirectory, $regular): void {
        foreach ([$home, $home.'/.deployments', $control, $control.'/state', $control.'/releases', $control.'/shared',
            $control.'/shared/public', $control.'/shared/public/ohif', $storage, $storage.'/framework', $stable, $stable.'/public'] as $directory) { $realDirectory($directory); }
        clearstatcache();
        if (file_exists($control.'/deploy.lock') || is_link($control.'/deploy.lock') || scandir($control.'/state') !== ['.', '..']
            || !is_link($stable.'/storage') || realpath($stable.'/storage') !== $storage
            || !is_link($stable.'/public/ohif') || realpath($stable.'/public/ohif') !== $control.'/shared/public/ohif') { throw new RuntimeException(); }
        $regular($stable.'/.deploy-release');
        if (filesize($stable.'/.deploy-release') > 4096) { throw new RuntimeException(); }
        $metadata = file_get_contents($stable.'/.deploy-release');
        preg_match_all('/^release=(.*)$/m', $metadata, $releases);
        preg_match_all('/^commit=(.*)$/m', $metadata, $commits);
        if ($releases[1] !== [$release] || $commits[1] !== [$commit]) { throw new RuntimeException(); }
    };
    $assertState();
    $ssh = $home.'/.ssh';
    $realDirectory($ssh);
    $authorized = $ssh.'/authorized_keys';
    $lockPath = $ssh.'/authorized_keys.lock';
    umask(0077);
    if (file_exists($lockPath) || is_link($lockPath)) {
        $regular($lockPath);
        $lock = fopen($lockPath, 'r+b');
    } else { $lock = fopen($lockPath, 'x+b'); }
    if ($lock === false || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException(); }
    $regular($lockPath);
    $opened = fstat($lock);
    if ($opened['ino'] !== fileinode($lockPath) || $opened['uid'] !== $uid) { throw new RuntimeException(); }
    $assertState();
    $original = '';
    $existed = file_exists($authorized) || is_link($authorized);
    if ($existed) {
        $regular($authorized);
        $original = file_get_contents($authorized);
        if ($original === false || str_contains($original, "\0")) { throw new RuntimeException(); }
    }
    $found = false;
    foreach (explode("\n", $original) as $line) {
        if (preg_match('/(?:\A|[ \t])ssh-ed25519[ \t]+'.preg_quote($key, '/').'(?:[ \t]|\z)/', $line) === 1) {
            // An unrestricted duplicate would defeat the new key's restrictions.
            if (!str_starts_with($line, 'restrict ') && !str_starts_with($line, 'restrict,')) { throw new RuntimeException(); }
            $found = true;
        }
    }
    if ($found) {
        $assertState();
        if (!chmod($authorized, 0600)) { throw new RuntimeException(); }
        echo "phr-production-key identity=exact restricted=yes authorized=unchanged\n";
        exit(0);
    }
    // A leading newline keeps our key separate even if a concurrent append has no newline.
    $addition = "\nrestrict ssh-ed25519 ".$key." phr-production-policy\n";
    $backup = $ssh.'/authorized_keys.phr-policy-backup-'.$run.'-'.$attempt;
    $backupHandle = fopen($backup, 'x+b');
    if ($backupHandle === false || !chmod($backup, 0600) || fwrite($backupHandle, $original) !== strlen($original) || !fflush($backupHandle)) { throw new RuntimeException(); }
    fclose($backupHandle);
    $assertState();
    // Existing files use O_APPEND; absent files use exclusive creation. Never rename
    // over authorized_keys: an uncooperative writer's intervening additions survive.
    clearstatcache();
    if ($existed !== (file_exists($authorized) || is_link($authorized))) { throw new RuntimeException(); }
    $identity = null;
    if ($existed) {
        $regular($authorized);
        if (file_get_contents($authorized) !== $original) { throw new RuntimeException(); }
        $identity = stat($authorized);
    }
    if (!$existed) {
        $created = fopen($authorized, 'x+b');
        if ($created === false) { throw new RuntimeException(); }
        $identity = fstat($created);
        fclose($created);
    }
    $authorizedHandle = fopen($authorized, 'ab');
    if ($authorizedHandle === false) { throw new RuntimeException(); }
    $openedKeys = fstat($authorizedHandle);
    $assertKeyIdentity = static function () use ($authorized, $authorizedHandle, $openedKeys, $identity, $regular, $uid): void {
        $regular($authorized);
        $current = stat($authorized);
        $handle = fstat($authorizedHandle);
        if (($handle['mode'] & 0170000) !== 0100000 || $handle['uid'] !== $uid
            || $handle['dev'] !== $openedKeys['dev'] || $handle['ino'] !== $openedKeys['ino']
            || $current['dev'] !== $handle['dev'] || $current['ino'] !== $handle['ino']
            || ($identity !== null && ($identity['dev'] !== $handle['dev'] || $identity['ino'] !== $handle['ino']))) { throw new RuntimeException(); }
    };
    $assertKeyIdentity();
    if (!chmod($authorized, 0600)) { throw new RuntimeException(); }
    $assertKeyIdentity();
    // One short append write; a partial write fails without restoring a stale backup.
    if (fwrite($authorizedHandle, $addition) !== strlen($addition) || !fflush($authorizedHandle)) { throw new RuntimeException(); }
    $assertKeyIdentity();
    $readback = file_get_contents($authorized);
    if ($readback === false || !str_starts_with($readback, $original)
        || !str_contains($readback, $addition) || (fileperms($authorized) & 0777) !== 0600) { throw new RuntimeException(); }
    fclose($authorizedHandle);
    $assertState();
    echo "phr-production-key identity=exact restricted=yes authorized=installed\n";
} catch (Throwable) {
    echo "PHR production key installation refused; details redacted.\n";
    exit(1);
}
PHP
