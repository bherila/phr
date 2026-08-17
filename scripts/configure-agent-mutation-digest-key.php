<?php

declare(strict_types=1);

/** Provision the stable agent mutation digest key without loading app configuration. */
$envFile = getenv('PHR_ENV_FILE') ?: __DIR__.'/../.env';

$fail = static function (string $message): never {
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
};

if (! is_file($envFile)) {
    $fail('PHR .env is missing; refusing to create the mutation digest key.');
}
$contents = file_get_contents($envFile);
if (! is_string($contents)) {
    $fail('PHR .env could not be read; refusing to create the mutation digest key.');
}

preg_match_all('/^AGENT_API_MUTATION_DIGEST_KEY=(.*)$/m', $contents, $matches);
$values = $matches[1] ?? [];
if (count($values) > 1) {
    $fail('AGENT_API_MUTATION_DIGEST_KEY appears more than once; refusing an ambiguous configuration.');
}

$value = $values[0] ?? null;
if ($value === null || $value === '') {
    $value = 'base64:'.base64_encode(random_bytes(32));
    if ($values === []) {
        $contents = rtrim($contents, "\r\n").PHP_EOL.'AGENT_API_MUTATION_DIGEST_KEY='.$value.PHP_EOL;
    } else {
        $contents = preg_replace(
            '/^AGENT_API_MUTATION_DIGEST_KEY=$/m',
            'AGENT_API_MUTATION_DIGEST_KEY='.$value,
            $contents,
            1,
        );
        if (! is_string($contents)) {
            $fail('PHR .env could not be updated.');
        }
    }

    $directory = dirname($envFile);
    $temporary = tempnam($directory, '.phr-env-');
    if (! is_string($temporary)) {
        $fail('PHR .env temporary file could not be created.');
    }
    if (file_put_contents($temporary, $contents, LOCK_EX) === false
        || ! chmod($temporary, 0600)
        || ! rename($temporary, $envFile)) {
        @unlink($temporary);
        $fail('PHR .env could not be updated atomically.');
    }
}

$encoded = str_starts_with($value, 'base64:') ? substr($value, 7) : '';
$decoded = base64_decode($encoded, true);
if (! preg_match('/^[A-Za-z0-9+\/]{43}=$/', $encoded)
    || ! is_string($decoded)
    || strlen($decoded) !== 32) {
    $fail('AGENT_API_MUTATION_DIGEST_KEY is malformed; refusing to continue.');
}

preg_match_all('/^APP_KEY=(.*)$/m', $contents, $appKeyMatches);
$appKeys = $appKeyMatches[1] ?? [];
if (count($appKeys) > 1) {
    $fail('APP_KEY appears more than once; refusing an ambiguous configuration.');
}
if (($appKeys[0] ?? null) === $value) {
    $fail('AGENT_API_MUTATION_DIGEST_KEY must be independent from APP_KEY.');
}

if (! chmod($envFile, 0600)) {
    $fail('PHR .env permissions could not be secured.');
}
fwrite(STDOUT, 'Persistent agent mutation digest key is configured.'.PHP_EOL);
