<?php
// eLeDia License Server — ED25519 bundle signer.
//
//   php bin/sign-bundle.php <bundle.json> [keyname]
//
// Reads the bundle, signs the raw bytes with
// data/keys/<keyname>.secret.key (default: demo), writes the signature
// as base64 to <bundle.json>.sig next to the bundle.

declare(strict_types=1);

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "libsodium not available.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/sign-bundle.php <bundle.json> [keyname]\n");
    exit(1);
}

$bundlepath = $argv[1];
$keyname    = $argv[2] ?? 'demo';

if (!is_readable($bundlepath)) {
    fwrite(STDERR, "Bundle not readable: {$bundlepath}\n");
    exit(1);
}

$secretpath = __DIR__ . '/../data/keys/' . $keyname . '.secret.key';
if (!is_readable($secretpath)) {
    fwrite(STDERR, "Private key not readable: {$secretpath}\n");
    fwrite(STDERR, "Run bin/generate-keypair.php {$keyname} first.\n");
    exit(1);
}

$secretbin = base64_decode(trim((string) file_get_contents($secretpath)), true);
if ($secretbin === false || strlen($secretbin) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Private key file is malformed.\n");
    exit(1);
}

$payload = (string) file_get_contents($bundlepath);
$signature = sodium_crypto_sign_detached($payload, $secretbin);

$sigpath = $bundlepath . '.sig';
file_put_contents($sigpath, base64_encode($signature) . "\n");

echo "Signed '{$bundlepath}' with key '{$keyname}'.\n";
echo "Signature written to: {$sigpath}\n";
echo "Signature length   : " . strlen($signature) . " bytes\n";
