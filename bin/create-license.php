<?php
// eLeDia License Server — insert a new license row.
//
//   php bin/create-license.php [--key=<uuid>]
//                              [--name="Customer"]
//                              [--email=customer@example.com]
//                              [--tier=starter]
//                              [--bundle=1.0.0]
//                              [--max-installs=3]
//                              [--expires=YYYY-MM-DD]
//
// If --key is omitted a fresh UUIDv4 is generated. Prints the license key
// on stdout so it can be piped into provisioning scripts.

declare(strict_types=1);

namespace EleDiaLicenseServer;

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';

$opts = getopt('', [
    'key::',
    'name::',
    'email::',
    'tier::',
    'bundle::',
    'max-installs::',
    'expires::',
]);

$key          = isset($opts['key'])          ? (string) $opts['key']          : generate_uuid_v4();
$name         = isset($opts['name'])         ? (string) $opts['name']         : null;
$email        = isset($opts['email'])        ? (string) $opts['email']        : null;
$tier         = isset($opts['tier'])         ? (string) $opts['tier']         : 'starter';
$bundle       = isset($opts['bundle'])       ? (string) $opts['bundle']       : '1.0.0';
$maxinstalls  = isset($opts['max-installs']) ? (int)    $opts['max-installs'] : 3;
$expires      = isset($opts['expires'])      ? (string) $opts['expires']      : null;

if (!is_valid_uuid($key)) {
    fwrite(STDERR, "Invalid license key — must be UUIDv4.\n");
    exit(1);
}
if ($expires !== null && !preg_match('#^\d{4}-\d{2}-\d{2}$#', $expires)) {
    fwrite(STDERR, "Invalid --expires format — use YYYY-MM-DD.\n");
    exit(1);
}

$db = new Database(__DIR__ . '/../data/licenses.sqlite');

try {
    $stmt = $db->pdo()->prepare(
        'INSERT INTO licenses
            (license_key, customer_name, customer_email, tier,
             current_bundle_version, max_installs, created_at, expires_at)
         VALUES
            (:k, :n, :e, :t, :b, :m, :c, :x)'
    );
    $stmt->execute([
        ':k' => $key,
        ':n' => $name,
        ':e' => $email,
        ':t' => $tier,
        ':b' => $bundle,
        ':m' => $maxinstalls,
        ':c' => now_utc_iso(),
        ':x' => $expires !== null ? ($expires . 'T23:59:59Z') : null,
    ]);
} catch (\PDOException $e) {
    fwrite(STDERR, "Insert failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Created license.\n";
echo "  license_key : {$key}\n";
echo "  customer    : " . ($name ?? '(none)') . "\n";
echo "  email       : " . ($email ?? '(none)') . "\n";
echo "  tier        : {$tier}\n";
echo "  bundle      : {$bundle}\n";
echo "  max_installs: {$maxinstalls}\n";
echo "  expires_at  : " . ($expires ?? '(never)') . "\n";
