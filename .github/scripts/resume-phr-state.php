<?php

// Filesystem-only guard: never execute application or generated cache PHP here.
declare(strict_types=1);

try {
    [, $release, $commit, $nonce, $inode, $mode, $maintenance, $cacheHash, $recoveryHash,
        $markerHash, $markerMtime, $incidentStart, $incidentEnd, $markerGuard] = $argv;
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5
        || ! preg_match('/\A[a-f0-9]{12}-[0-9]+-[0-9]+\z/', $release)
        || ! preg_match('/\A[a-f0-9]{40}\z/', $commit)
        || ! preg_match('/\Aresume-[a-f0-9]{32}\z/', $nonce)
        || ! in_array($mode, ['idle', 'owned'], true)
        || ! in_array($maintenance, ['present', 'absent', 'either'], true)) {
        exit(1);
    }
    $home = getenv('HOME');
    $stable = $home.'/phr-laravel';
    $control = $home.'/.deployments/phr-laravel';
    $storage = $control.'/shared/storage';
    foreach ([$home, $home.'/.deployments', $control, $control.'/state', $control.'/releases', $control.'/shared',
        $control.'/shared/public', $control.'/shared/public/ohif', $storage, $storage.'/framework',
        $storage.'/app', $storage.'/app/private', $storage.'/app/private/oauth',
        $stable, $stable.'/public', $stable.'/bootstrap', $stable.'/bootstrap/cache', $control.'/recovery'] as $path) {
        if (! is_dir($path) || is_link($path) || realpath($path) !== $path) {
            exit(1);
        }
    }
    if (scandir($control.'/state') !== ['.', '..']
        || ! is_link($stable.'/storage') || realpath($stable.'/storage') !== $storage
        || ! is_link($stable.'/public/ohif') || realpath($stable.'/public/ohif') !== $control.'/shared/public/ohif') {
        exit(1);
    }
    $lock = $control.'/deploy.lock';
    if ($mode === 'idle') {
        if (file_exists($lock) || is_link($lock)) {
            exit(1);
        }
    } else {
        if (! is_dir($lock) || is_link($lock) || realpath($lock) !== $lock) {
            exit(1);
        }
        $stat = stat($lock);
        if ($inode !== $stat['dev'].':'.$stat['ino']
            || ! is_file($lock.'/owner') || is_link($lock.'/owner')
            || filesize($lock.'/owner') !== strlen($nonce) + 1
            || file_get_contents($lock.'/owner') !== $nonce."\n"
            || scandir($lock) !== ['.', '..', 'owner']) {
            exit(1);
        }
    }
    $regular = static function (string $path, int $bound): void {
        if (! is_file($path) || is_link($path) || realpath($path) !== $path || filesize($path) > $bound) {
            exit(1);
        }
    };
    $metadataPath = $stable.'/.deploy-release';
    $regular($metadataPath, 4096);
    $metadata = file_get_contents($metadataPath);
    preg_match_all('/^release=(.*)$/m', $metadata, $releases);
    preg_match_all('/^commit=(.*)$/m', $metadata, $commits);
    if ($releases[1] !== [$release] || $commits[1] !== [$commit]) {
        exit(1);
    }
    $cache = $stable.'/bootstrap/cache/config.php';
    $recovery = $control.'/recovery/'.$release.'.cron';
    $regular($cache, 4 * 1024 * 1024);
    $regular($recovery, 1024 * 1024);
    foreach (['private', 'public'] as $kind) {
        $path = $storage.'/app/private/oauth/oauth-'.$kind.'.key';
        $regular($path, 65536);
        $hash = getenv('RESUME_OAUTH_'.strtoupper($kind).'_HASH') ?: '-';
        if (! is_readable($path) || filesize($path) < 1 || ($hash !== '-' && hash_file('sha256', $path) !== $hash)) {
            exit(1);
        }
    }
    if (($cacheHash !== '-' && hash_file('sha256', $cache) !== $cacheHash)
        || ($recoveryHash !== '-' && hash_file('sha256', $recovery) !== $recoveryHash)) {
        exit(1);
    }
    $down = $storage.'/framework/down';
    $rendered = $storage.'/framework/maintenance.php';
    foreach ([$down, $rendered] as $path) {
        if (is_link($path) || (file_exists($path) && (! is_file($path) || realpath($path) !== $path))) {
            exit(1);
        }
    }
    if (($maintenance === 'present' && ! is_file($down)) || ($maintenance === 'absent' && is_file($down))) {
        exit(1);
    }
    if ($markerGuard === 'true') {
        $regular($down, 1024 * 1024);
        $mtime = filemtime($down);
        if ($mtime < (int) $incidentStart || $mtime > (int) $incidentEnd
            || ($markerHash !== '-' && hash_file('sha256', $down) !== $markerHash)
            || ($markerMtime !== '-' && (string) $mtime !== $markerMtime)) {
            exit(1);
        }
    } elseif ($markerGuard !== 'false') {
        exit(1);
    }
    echo "phr-resume state=validated\n";
} catch (Throwable) {
    exit(1);
}
