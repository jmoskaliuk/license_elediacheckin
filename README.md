# eLeDia License Server — MVP

Tiny, single-process PHP license server for the `mod_elediacheckin` plugin.
Phase 2 per `docs/content-distribution-konzept.md` §4.

**Scope of the MVP:** `/verify` + `/bundle/{version}` + `/signature/{version}`
endpoints, SQLite-backed key/install store, ED25519 content signing, demo
bundle pre-signed with the demo key pair. Keys are created by hand via
`bin/create-license.php` — no Lemon Squeezy, no admin dashboard, no
automatic key provisioning. That all lives in Phase 3.

**What is intentionally NOT here**
- Merchant integration (Lemon Squeezy / Stripe)
- Admin UI (keys are managed via CLI + direct SQL)
- Monitoring, rate limiting, HTTPS (reverse-proxy it in prod)
- Multi-tenant / multi-plugin support (one server = one plugin = one
  signing key for now)

## Directory layout

```
license_server/
├── bin/
│   ├── generate-keypair.php    # ED25519 keypair → data/keys/*.key
│   ├── sign-bundle.php          # signs a JSON bundle with the demo priv key
│   ├── create-license.php       # inserts a license row into SQLite
│   └── seed-demo.php            # creates DB + demo key + demo license
├── public/
│   └── index.php                # HTTP front controller (serve with built-in PHP)
├── src/
│   ├── Database.php             # PDO/SQLite wrapper + bootstrap
│   ├── TokenMinter.php          # HMAC-signed bearer tokens
│   ├── VerifyController.php     # POST /verify
│   ├── BundleController.php     # GET /bundle/{version} + GET /signature/{version}
│   └── helpers.php              # JSON response, UUID parsing, etc.
├── data/                        # runtime state (gitignored)
│   ├── licenses.sqlite
│   ├── keys/
│   │   ├── demo.secret.key      # ED25519 private, base64 (DEMO ONLY)
│   │   └── demo.public.key      # ED25519 public, hex (DEMO ONLY)
│   └── bundles/
│       ├── premium-v1.0.0.json
│       └── premium-v1.0.0.json.sig
└── .env.example
```

## First-time setup (local dev)

```bash
cd license_server
php bin/seed-demo.php              # creates DB + demo keypair + demo license
php -S 127.0.0.1:8787 public/index.php
```

The seed script prints a license UUID at the end — paste that into the
Moodle admin setting `mod_elediacheckin/licensekey` together with the URL
`http://host.docker.internal:8787` (or `http://127.0.0.1:8787` if the
plugin can reach the host directly).

It also prints the hex-encoded public key. Copy it into
`mod_elediacheckin/classes/content/bundle_signature_verifier.php` —
constant `ELEDIA_PREMIUM_PUBLIC_KEY_HEX` — so the plugin trusts bundles
signed by this local keypair. (A release build would ship the real
eLeDia production public key instead.)

## Endpoints

### POST /verify

Request JSON:
```json
{
  "license_key": "550e8400-e29b-41d4-a716-446655440000",
  "site_hash":   "3f9c7bfc…a1b",
  "site_url":    "https://moodle.example.com",
  "plugin_version": "2026040521",
  "component":   "mod_elediacheckin"
}
```

Response 200:
```json
{
  "token":          "eyJhbGciOiJIUzI1NiJ9…",
  "bundle_version": "1.0.0",
  "bundle_url":     "http://127.0.0.1:8787/bundle/1.0.0",
  "signature_url":  "http://127.0.0.1:8787/signature/1.0.0",
  "expires_at":     "2026-04-07T17:00:00Z"
}
```

Response 401 on unknown / revoked key, 403 on `max_installs` exceeded.

### GET /bundle/{version}

Requires `Authorization: Bearer <token>` from /verify. Returns the raw
JSON bytes of `data/bundles/premium-v{version}.json`.

### GET /signature/{version}

Same auth as /bundle. Returns the base64-encoded ED25519 signature from
`data/bundles/premium-v{version}.json.sig`.

### GET /health

Unauthenticated. Returns `{"status": "ok", "bundles": [...versions...]}`.

## Notes

- Tokens are stateless HMAC blobs over `{license_id, bundle_version, exp}`,
  signed with `APP_SECRET` from `.env`. No token table needed.
- Signing is deliberately offline: `bin/sign-bundle.php` reads the private
  key from `data/keys/demo.secret.key`, writes `<bundle>.sig` next to
  the bundle. In production the private key lives in a GitHub Actions
  secret and the CI pipeline does the signing (see Konzept §5).
- The license table uses UUID v4 keys (122 bits of entropy). No rate
  limiting in the MVP — add it before exposing the server publicly.
