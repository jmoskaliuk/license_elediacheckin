<?php
// eLeDia License Server — one-shot demo seeder.
//
//   php bin/seed-demo.php
//
// Does four things, idempotently:
//   1. Ensures data/keys/demo.{secret,public}.key exists (generates if missing).
//   2. Ensures data/licenses.sqlite exists with a demo license row.
//   3. Ensures data/bundles/premium-v1.0.0.json exists (by copying the
//      plugin's db/content/default.json next door).
//   4. Signs the demo bundle with the demo key.
//
// Output:
//   - License key (UUID) — paste into plugin "licensekey" setting.
//   - Public key hex     — paste into bundle_signature_verifier.php.

declare(strict_types=1);

namespace EleDiaLicenseServer;

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "libsodium required.\n");
    exit(1);
}

$root       = dirname(__DIR__);
$keydir     = $root . '/data/keys';
$bundledir  = $root . '/data/bundles';
$dbpath     = $root . '/data/licenses.sqlite';
$secretpath = $keydir . '/demo.secret.key';
$publicpath = $keydir . '/demo.public.key';

if (!is_dir($keydir)) {
    mkdir($keydir, 0700, true);
}
if (!is_dir($bundledir)) {
    mkdir($bundledir, 0755, true);
}

// --- 1. Keypair -------------------------------------------------------
if (!file_exists($secretpath) || !file_exists($publicpath)) {
    $keypair   = sodium_crypto_sign_keypair();
    $secretbin = sodium_crypto_sign_secretkey($keypair);
    $publicbin = sodium_crypto_sign_publickey($keypair);
    file_put_contents($secretpath, base64_encode($secretbin) . "\n");
    chmod($secretpath, 0600);
    file_put_contents($publicpath, bin2hex($publicbin) . "\n");
    chmod($publicpath, 0644);
    echo "[seed] Generated fresh demo keypair.\n";
} else {
    echo "[seed] Reusing existing demo keypair.\n";
}
$publickeyhex = trim((string) file_get_contents($publicpath));

// --- 2. License row ---------------------------------------------------
$db = new Database($dbpath);
$existing = $db->pdo()->query(
    "SELECT license_key FROM licenses WHERE customer_email = 'demo@eledia.de' LIMIT 1"
)->fetch();

if ($existing !== false) {
    $licensekey = (string) $existing['license_key'];
    echo "[seed] Reusing existing demo license.\n";
} else {
    $licensekey = generate_uuid_v4();
    $stmt = $db->pdo()->prepare(
        'INSERT INTO licenses
            (license_key, customer_name, customer_email, tier,
             current_bundle_version, max_installs, created_at)
         VALUES (:k, :n, :e, :t, :b, :m, :c)'
    );
    $stmt->execute([
        ':k' => $licensekey,
        ':n' => 'eLeDia Demo',
        ':e' => 'demo@eledia.de',
        ':t' => 'premium',
        ':b' => '1.0.0',
        ':m' => 10,
        ':c' => now_utc_iso(),
    ]);
    echo "[seed] Created fresh demo license.\n";
}

// --- 3. Demo bundle ---------------------------------------------------
$bundlepath = $bundledir . '/premium-v1.0.0.json';
// license_server is a sibling of the plugin inside the workspace repo.
$sourcebundle = $root . '/../mod_elediacheckin/db/content/default.json';

if (!file_exists($bundlepath)) {
    if (!is_readable($sourcebundle)) {
        fwrite(STDERR, "[seed] Source bundle not found at {$sourcebundle}\n");
        fwrite(STDERR, "       Drop a bundle.json into data/bundles/premium-v1.0.0.json manually.\n");
        exit(1);
    }
    $raw = (string) file_get_contents($sourcebundle);
    // Rewrite bundle_id + bundle_version to mark it as the demo premium.
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $decoded['bundle_id']      = 'eledia-premium-demo';
        $decoded['bundle_version'] = '1.0.0';
        $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    file_put_contents($bundlepath, $raw);
    echo "[seed] Wrote demo bundle to data/bundles/premium-v1.0.0.json\n";
} else {
    echo "[seed] Reusing existing demo bundle.\n";
}

// --- 4. Sign the bundle -----------------------------------------------
$secretbin = base64_decode(trim((string) file_get_contents($secretpath)), true);
if ($secretbin === false || strlen($secretbin) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "[seed] Demo secret key is malformed.\n");
    exit(1);
}
$payload = (string) file_get_contents($bundlepath);
$signature = sodium_crypto_sign_detached($payload, $secretbin);
file_put_contents($bundlepath . '.sig', base64_encode($signature) . "\n");
echo "[seed] (Re)signed demo bundle.\n";

// --- Summary ----------------------------------------------------------
echo "\n";
echo "============================================================\n";
echo " eLeDia License Server — demo seed complete\n";
echo "============================================================\n";
echo " License key (paste into plugin setting 'licensekey'):\n";
echo "     {$licensekey}\n";
echo "\n";
echo " Public key hex (paste into\n";
echo " classes/content/bundle_signature_verifier.php\n";
echo " as ELEDIA_PREMIUM_PUBLIC_KEY_HEX):\n";
echo "     {$publickeyhex}\n";
echo "\n";
echo " Start the server with:\n";
echo "     php -S 127.0.0.1:8787 public/index.php\n";
echo "============================================================\n";
