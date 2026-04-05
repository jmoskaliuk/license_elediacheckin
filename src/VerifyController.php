<?php
// eLeDia License Server — POST /verify.

declare(strict_types=1);

namespace EleDiaLicenseServer;

final class VerifyController {

    public function __construct(
        private Database $db,
        private TokenMinter $tokens,
        private string $publicbaseurl
    ) {}

    public function handle(): void {
        $body = read_json_body();

        $licensekey    = isset($body['license_key'])    ? (string) $body['license_key']    : '';
        $sitehash      = isset($body['site_hash'])      ? (string) $body['site_hash']      : '';
        $siteurl       = isset($body['site_url'])       ? (string) $body['site_url']       : null;
        $pluginversion = isset($body['plugin_version']) ? (string) $body['plugin_version'] : null;

        if (!is_valid_uuid($licensekey)) {
            $this->db->log_event(null, 'verify.invalid_key_format');
            json_response(400, ['error' => 'invalid_license_key_format']);
        }
        if (strlen($sitehash) !== 64 || !ctype_xdigit($sitehash)) {
            json_response(400, ['error' => 'invalid_site_hash']);
        }

        $license = $this->db->find_license_by_key($licensekey);
        if ($license === null) {
            $this->db->log_event(null, 'verify.unknown_key');
            json_response(401, ['error' => 'license_not_found']);
        }

        if (!empty($license['revoked_at'])) {
            $this->db->log_event((int) $license['id'], 'verify.revoked');
            json_response(401, ['error' => 'license_revoked']);
        }
        if (!empty($license['expires_at']) && $license['expires_at'] < now_utc_iso()) {
            $this->db->log_event((int) $license['id'], 'verify.expired');
            json_response(401, ['error' => 'license_expired']);
        }

        $allowed = $this->db->register_install($license, $sitehash, $siteurl, $pluginversion);
        if (!$allowed) {
            $this->db->log_event((int) $license['id'], 'verify.max_installs');
            json_response(403, [
                'error' => 'max_installs_exceeded',
                'max_installs' => (int) $license['max_installs'],
            ]);
        }

        $bundleversion = (string) $license['current_bundle_version'];
        $ticket = $this->tokens->mint((int) $license['id'], $bundleversion);

        $this->db->log_event((int) $license['id'], 'verify.ok', $bundleversion);

        $base = rtrim($this->publicbaseurl, '/');
        json_response(200, [
            'token'          => $ticket['token'],
            'bundle_version' => $bundleversion,
            'bundle_url'     => $base . '/bundle/' . rawurlencode($bundleversion),
            'signature_url'  => $base . '/signature/' . rawurlencode($bundleversion),
            'expires_at'     => $ticket['expires_at'],
            'tier'           => (string) $license['tier'],
        ]);
    }
}
