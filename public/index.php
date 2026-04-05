<?php
// eLeDia License Server — HTTP front controller.
//
// Run via the built-in PHP server for local dev:
//   php -S 127.0.0.1:8787 public/index.php
//
// In production put this behind nginx/Apache as a FastCGI app and
// make sure the server terminates HTTPS before reaching PHP.

declare(strict_types=1);

namespace EleDiaLicenseServer;

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/TokenMinter.php';
require_once __DIR__ . '/../src/VerifyController.php';
require_once __DIR__ . '/../src/BundleController.php';

load_env(__DIR__ . '/../.env');

$secret = env('APP_SECRET');
if ($secret === null || strlen($secret) < 32) {
    json_response(500, ['error' => 'APP_SECRET not configured (see .env.example)']);
}
$publicbase = env('PUBLIC_BASE_URL', 'http://127.0.0.1:8787');
$ttl = (int) env('TOKEN_TTL', '86400');

$dbpath = __DIR__ . '/../data/licenses.sqlite';
$bundlesdir = __DIR__ . '/../data/bundles';

$db     = new Database($dbpath);
$tokens = new TokenMinter($secret, $ttl);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';

// --- Routing -----------------------------------------------------------
try {
    if ($method === 'GET' && $path === '/health') {
        $versions = [];
        foreach ((array) glob($bundlesdir . '/premium-v*.json') as $file) {
            if (preg_match('#premium-v(.+)\.json$#', (string) $file, $m)) {
                $versions[] = $m[1];
            }
        }
        sort($versions);
        json_response(200, [
            'status'  => 'ok',
            'time'    => now_utc_iso(),
            'bundles' => $versions,
        ]);
    }

    if ($method === 'POST' && $path === '/verify') {
        (new VerifyController($db, $tokens, (string) $publicbase))->handle();
    }

    if ($method === 'GET' && preg_match('#^/bundle/([^/]+)$#', $path, $m)) {
        (new BundleController($db, $tokens, $bundlesdir))->bundle(rawurldecode($m[1]));
    }
    if ($method === 'GET' && preg_match('#^/signature/([^/]+)$#', $path, $m)) {
        (new BundleController($db, $tokens, $bundlesdir))->signature(rawurldecode($m[1]));
    }

    json_response(404, ['error' => 'route_not_found', 'path' => $path, 'method' => $method]);
} catch (\Throwable $e) {
    // Log to stderr so `php -S` prints it in the dev console.
    fwrite(STDERR, '[license_server] ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    json_response(500, ['error' => 'internal_error', 'message' => $e->getMessage()]);
}
