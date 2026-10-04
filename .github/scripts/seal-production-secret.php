<?php

try {
    $secret = getenv('DEPLOY_KEY');
    $public = base64_decode((string) getenv('SECRET_PUBLIC_KEY'), true);
    $id = getenv('SECRET_KEY_ID');
    $destination = getenv('SECRET_COPY_DESTINATION');
    if (! is_string($secret) || $secret === '' || strlen($secret) > 49152
        || ! is_string($public) || strlen($public) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
        || ! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)
        || ! is_string($destination) || $destination === '') {
        throw new RuntimeException('invalid copy input');
    }
    $payload = json_encode(['key_id' => $id, 'encrypted_value' => base64_encode(sodium_crypto_box_seal($secret, $public))], JSON_THROW_ON_ERROR)."\n";
    umask(0077);
    $file = fopen($destination, 'x');
    if ($file === false || fwrite($file, $payload) !== strlen($payload) || ! fclose($file)) {
        throw new RuntimeException('encrypted copy write');
    }
    echo "GitHub-sealed credential payload prepared.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Credential copy failed; details redacted.\n");
    exit(1);
}
