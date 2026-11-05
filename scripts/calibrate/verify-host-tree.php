<?php

/** Standalone PHP core only: no environment, vendor or application bootstrap. */
function verifySyntheticTree(string $root, string $expectedDigest, string $candidate): array
{
    $manifestPath = $root.'/tree-manifest.json';
    $application = $root.'/application';
    if (! preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) || ! preg_match('/^[a-f0-9]{40}$/D', $candidate)
        || is_link($root) || realpath($root) !== $root || is_link($application) || realpath($application) !== $application
        || is_link($manifestPath) || ! is_file($manifestPath) || filesize($manifestPath) > 16 * 1024 * 1024
        || ! hash_equals($expectedDigest, hash_file('sha256', $manifestPath))) {
        throw new RuntimeException('manifest_identity');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? null) !== 'synthetic-phr-tree-v1' || ($manifest['candidate'] ?? null) !== $candidate
        || ! is_array($manifest['files'] ?? null) || ! is_array($manifest['directories'] ?? null)
        || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['lock_sha256'] ?? '')
        || count($manifest['files']) + count($manifest['directories']) > 50000) {
        throw new RuntimeException('manifest_shape');
    }
    $safe = static function (string $name): bool {
        $parts = explode('/', $name);
        $roots = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'scripts', 'vendor', 'composer.json', 'composer.lock', 'artisan', 'calibration-source.json'];

        return $name !== '' && ! preg_match('/[\\\\\x00-\x1f\x7f]/', $name)
            && in_array($parts[0], $roots, true) && array_intersect($parts, ['', '.', '..', '.env', '.git']) === []
            && $name !== 'bootstrap/cache' && ! str_starts_with($name, 'bootstrap/cache/');
    };
    $expectedDirectories = [];
    foreach ($manifest['directories'] as $directory) {
        if (! is_string($directory) || ! $safe($directory) || isset($expectedDirectories[$directory])) {
            throw new RuntimeException('directory_shape');
        }
        $expectedDirectories[$directory] = true;
    }
    $bytes = 0;
    foreach ($manifest['files'] as $name => $file) {
        if (! is_string($name) || ! $safe($name) || isset($expectedDirectories[$name]) || ! is_array($file)
            || ! is_int($file['bytes'] ?? null) || $file['bytes'] < 0 || $file['bytes'] > 128 * 1024 * 1024
            || ! preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '')) {
            throw new RuntimeException('file_shape');
        }
        $bytes += $file['bytes'];
        if ($bytes > 500 * 1024 * 1024) {
            throw new RuntimeException('tree_size');
        }
    }
    $size = $manifest['bundle_size'] ?? [];
    if (! is_int($size['compressed_bytes'] ?? null) || $size['compressed_bytes'] <= 0 || $size['compressed_bytes'] > 80 * 1024 * 1024
        || ($size['expanded_bytes'] ?? null) !== $bytes || ! is_int($size['members'] ?? null)
        || $size['members'] < count($manifest['files']) || $size['members'] > 50000
        || ($manifest['tree_members'] ?? null) !== count($manifest['files']) + count($expectedDirectories)
        || $size['members'] > $manifest['tree_members']) {
        throw new RuntimeException('tree_budget');
    }
    $seenFiles = [];
    $seenDirectories = [];
    $stack = [[$application, '']];
    while ($stack !== []) {
        [$directory, $prefix] = array_pop($stack);
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $name = $prefix.$entry->getFilename();
            $path = $entry->getPathname();
            $kind = lstat($path)['mode'] & 0170000;
            if ($kind === 0040000 && isset($expectedDirectories[$name])) {
                $seenDirectories[$name] = true;
                $stack[] = [$path, $name.'/'];
            } elseif ($kind === 0100000 && isset($manifest['files'][$name])) {
                $file = $manifest['files'][$name];
                if (filesize($path) !== $file['bytes'] || ! hash_equals($file['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('file_integrity');
                }
                $seenFiles[$name] = true;
            } else {
                // Never recurse into a link, unknown directory, device or extra file.
                throw new RuntimeException('unexpected_entry');
            }
        }
    }
    if (count($seenFiles) !== count($manifest['files']) || count($seenDirectories) !== count($expectedDirectories)) {
        throw new RuntimeException('missing_entry');
    }
    foreach (['artisan', 'vendor/autoload.php', 'composer.lock', 'calibration-source.json'] as $required) {
        if (! isset($seenFiles[$required])) {
            throw new RuntimeException('required_source');
        }
    }
    $sourcePath = $application.'/calibration-source.json';
    if (filesize($sourcePath) > 4096) {
        throw new RuntimeException('source_size');
    }
    $source = json_decode(file_get_contents($sourcePath), true, 8, JSON_THROW_ON_ERROR);
    if ($source !== ['candidate' => $candidate, 'lock_sha256' => $manifest['lock_sha256']]
        || ! hash_equals($manifest['lock_sha256'], hash_file('sha256', $application.'/composer.lock'))) {
        throw new RuntimeException('source_identity');
    }

    return $size;
}

try {
    if ($argc !== 4) {
        throw new RuntimeException('arguments');
    }
    echo json_encode(verifySyntheticTree($argv[1], $argv[2], $argv[3]), JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    echo '{"stage":"tree_admission","status":"refused","code":"manifest_invalid"}'."\n";
    exit(1);
}
