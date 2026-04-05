<?php
// eLeDia License Server — ED25519 keypair generator.
//
//   php bin/generate-keypair.php [name]
//
// Creates data/keys/<name>.secret.key (base64) + data/keys/<name>.public.key (hex).
// Default name: demo. Never overwrites an existing keypair.

declare(strict_types=1);

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "libsodium not available — need PHP 7.2+ with ext-sodium.\n");
    exit(1);
}

$name = $argv[1] ?? 'demo';
if (!preg_match('#^[a-z0-9_-]{1,32}$#', $name)) {
    fwrite(STDERR, "Invalid key name. Use [a-z0-9_-], max 32 chars.\n");
    exit(1);
}

$keydir = __DIR__ . '/../data/keys';
if (!is_dir($keydir)) {
    mkdir($keydir, 0700, true);
}

$secretpath = $keydir . '/' . $name . '.secret.key';
$publicpath = $keydir . '/' . $name . '.public.key';
if (file_exists($secretpath) || file_exists($publicpath)) {
    fwrite(STDERR, "Keypair '{$name}' already exists — refusing to overwrite.\n");
    fwrite(STDERR, "Delete the existing files manually if you really want to rotate.\n");
    exit(1);
}

$keypair   = sodium_crypto_sign_keypair();
$secretbin = sodium_crypto_sign_secretkey($keypair);
$publicbin = sodium_crypto_sign_publickey($keypair);

file_put_contents($secretpath, base64_encode($secretbin) . "\n");
chmod($secretpath, 0600);

file_put_contents($publicpath, bin2hex($publicbin) . "\n");
chmod($publicpath, 0644);

echo "Generated ED25519 keypair '{$name}'.\n";
echo "  Private key (base64): {$secretpath}\n";
echo "  Public  key (hex)   : {$publicpath}\n";
echo "\n";
echo "Public key hex (paste into bundle_signature_verifier.php,\n";
echo "constant ELEDIA_PREMIUM_PUBLIC_KEY_HEX):\n\n";
echo bin2hex($publicbin) . "\n";
