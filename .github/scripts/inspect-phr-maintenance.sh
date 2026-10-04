#!/usr/bin/env bash
# Remote read-only identity/state proof. Raw PHP output is validated by the runner.
set -euo pipefail
[[ $# == 3 && $1 =~ ^/[A-Za-z0-9/._-]+$ && -x $1 \
    && $2 =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ && $3 =~ ^[a-f0-9]{40}$ ]] || exit 2
ulimit -f 1024
timeout --kill-after=2s 30s "$1" -d memory_limit=1G -d display_errors=0 -d log_errors=0 /dev/stdin "$HOME" "$2" "$3" <<'PHP'
<?php
try {
    [, $home, $release, $commit] = $argv;
    $stable = $home.'/phr-laravel';
    $control = $home.'/.deployments/phr-laravel';
    $storage = $control.'/shared/storage';
    $assertState = static function () use ($home, $stable, $control, $storage, $release, $commit): void {
        foreach ([$home, $home.'/.deployments', $control, $control.'/state', $control.'/shared', $storage, $stable] as $directory) {
            if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) { throw new RuntimeException(); }
        }
        if (file_exists($control.'/deploy.lock') || is_link($control.'/deploy.lock')
            || scandir($control.'/state') !== ['.', '..']
            || !is_link($stable.'/storage') || realpath($stable.'/storage') !== $storage) { throw new RuntimeException(); }
        $metadataPath = $stable.'/.deploy-release';
        if (!is_file($metadataPath) || is_link($metadataPath) || filesize($metadataPath) > 4096) { throw new RuntimeException(); }
        $metadata = file_get_contents($metadataPath);
        preg_match_all('/^release=(.*)$/m', $metadata, $releases);
        preg_match_all('/^commit=(.*)$/m', $metadata, $commits);
        if ($releases[1] !== [$release] || $commits[1] !== [$commit]) { throw new RuntimeException(); }
    };
    $assertState();
    $recovery = $control.'/recovery';
    if (!is_dir($recovery) || is_link($recovery) || realpath($recovery) !== $recovery) { exit(1); }
    $cron = $recovery.'/'.$release.'.cron';
    if (is_link($cron) || (file_exists($cron) && (!is_file($cron) || realpath($cron) !== $cron))) { exit(1); }
    $cronPresent = is_file($cron);
    chdir($stable);
    ob_start();
    require $stable.'/vendor/autoload.php';
    $app = require $stable.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $maintenance = $app->isDownForMaintenance();
    ob_end_clean();
    $assertState();
    echo 'phr-maintenance identity=exact roots=canonical maintenance='.($maintenance ? 'yes' : 'no').
        ' lock=absent transactions=0 recovery_cron='.($cronPresent ? 'present' : 'absent')."\n";
} catch (Throwable) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    exit(1);
}
PHP
