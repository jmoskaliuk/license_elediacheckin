<?php
// eLeDia License Server — GET /bundle/{v} + GET /signature/{v}.

declare(strict_types=1);

namespace EleDiaLicenseServer;

final class BundleController {

    public function __construct(
        private Database $db,
        private TokenMinter $tokens,
        private string $bundlesdir
    ) {}

    public function bundle(string $version): void {
        $payload = $this->authorise($version);
        $path = $this->path_for($version, '.json');
        if (!is_readable($path)) {
            json_response(404, ['error' => 'bundle_not_found', 'version' => $version]);
        }
        $this->db->log_event($payload['lid'] ?? null, 'bundle.fetch', $version);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        readfile($path);
        exit;
    }

    public function signature(string $version): void {
        $payload = $this->authorise($version);
        $path = $this->path_for($version, '.json.sig');
        if (!is_readable($path)) {
            json_response(404, ['error' => 'signature_not_found', 'version' => $version]);
        }
        $this->db->log_event($payload['lid'] ?? null, 'signature.fetch', $version);
        header('Content-Type: text/plain; charset=us-ascii');
        header('Cache-Control: private, max-age=300');
        readfile($path);
        exit;
    }

    private function authorise(string $version): array {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('#^Bearer\s+(.+)$#i', $header, $m)) {
            json_response(401, ['error' => 'missing_bearer_token']);
        }
        $payload = $this->tokens->verify(trim($m[1]));
        if ($payload === null) {
            json_response(401, ['error' => 'invalid_or_expired_token']);
        }
        if (($payload['bv'] ?? '') !== $version) {
            json_response(403, ['error' => 'token_bundle_mismatch']);
        }
        return $payload;
    }

    private function path_for(string $version, string $suffix): string {
        // Whitelist: only [0-9.a-zA-Z-] is allowed in the version path
        // to prevent traversal.
        if (!preg_match('#^[0-9a-zA-Z.\-]{1,32}$#', $version)) {
            json_response(400, ['error' => 'invalid_version']);
        }
        return rtrim($this->bundlesdir, '/') . '/premium-v' . $version . $suffix;
    }
}
