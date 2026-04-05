<?php
// eLeDia License Server — stateless bearer tokens.
//
// Compact JSON-over-HMAC token. No JWT library needed: the payload is
// base64url(JSON) + '.' + base64url(HMAC-SHA256). One secret, one
// algorithm, no negotiation — `alg: none` CVEs are impossible by
// construction.

declare(strict_types=1);

namespace EleDiaLicenseServer;

final class TokenMinter {

    public function __construct(
        private string $secret,
        private int $ttlseconds = 86400
    ) {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('APP_SECRET must be at least 32 characters.');
        }
    }

    public function mint(int $licenseid, string $bundleversion): array {
        $now = time();
        $exp = $now + $this->ttlseconds;
        $payload = [
            'lid' => $licenseid,
            'bv'  => $bundleversion,
            'iat' => $now,
            'exp' => $exp,
        ];
        $encoded = self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64url(hash_hmac('sha256', $encoded, $this->secret, true));
        return [
            'token'      => $encoded . '.' . $sig,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    /**
     * @return array|null Payload array on success, null on malformed/expired/forged.
     */
    public function verify(string $token): ?array {
        if (!str_contains($token, '.')) {
            return null;
        }
        [$encoded, $sig] = explode('.', $token, 2);
        $expected = self::b64url(hash_hmac('sha256', $encoded, $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = self::b64url_decode($encoded);
        if ($json === null) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $encoded): ?string {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
