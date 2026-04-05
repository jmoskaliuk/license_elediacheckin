<?php
// eLeDia License Server — SQLite wrapper.
//
// Three tables: licenses, installs, usage_log. Schema is created lazily
// on first connection so the server is self-bootstrapping for dev.

declare(strict_types=1);

namespace EleDiaLicenseServer;

final class Database {

    private \PDO $pdo;

    public function __construct(string $sqlitepath) {
        $dir = dirname($sqlitepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = new \PDO('sqlite:' . $sqlitepath, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->migrate();
    }

    public function pdo(): \PDO {
        return $this->pdo;
    }

    private function migrate(): void {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS licenses (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                license_key     TEXT NOT NULL UNIQUE,
                customer_name   TEXT,
                customer_email  TEXT,
                tier            TEXT NOT NULL DEFAULT 'starter',
                current_bundle_version TEXT NOT NULL DEFAULT '1.0.0',
                max_installs    INTEGER NOT NULL DEFAULT 3,
                created_at      TEXT NOT NULL,
                expires_at      TEXT,
                revoked_at      TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS installs (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                license_id      INTEGER NOT NULL,
                site_hash       TEXT NOT NULL,
                site_url        TEXT,
                plugin_version  TEXT,
                first_seen      TEXT NOT NULL,
                last_seen       TEXT NOT NULL,
                UNIQUE(license_id, site_hash),
                FOREIGN KEY (license_id) REFERENCES licenses(id)
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS usage_log (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                license_id      INTEGER,
                event           TEXT NOT NULL,
                bundle_version  TEXT,
                at              TEXT NOT NULL,
                FOREIGN KEY (license_id) REFERENCES licenses(id)
            );
        SQL);
    }

    public function find_license_by_key(string $key): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE license_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Upsert an install and return true if the caller is allowed, false if
     * adding this install would exceed max_installs. Existing (license, hash)
     * tuples are always allowed — only new hashes count against the limit.
     */
    public function register_install(array $license, string $sitehash, ?string $siteurl, ?string $pluginversion): bool {
        $now = now_utc_iso();

        // Look up existing.
        $stmt = $this->pdo->prepare(
            'SELECT id FROM installs WHERE license_id = :lid AND site_hash = :sh LIMIT 1'
        );
        $stmt->execute([':lid' => $license['id'], ':sh' => $sitehash]);
        $existing = $stmt->fetch();

        if ($existing !== false) {
            $upd = $this->pdo->prepare(
                'UPDATE installs
                    SET last_seen = :now,
                        site_url = :url,
                        plugin_version = :pv
                  WHERE id = :id'
            );
            $upd->execute([
                ':now' => $now,
                ':url' => $siteurl,
                ':pv'  => $pluginversion,
                ':id'  => $existing['id'],
            ]);
            return true;
        }

        // New install — count existing against max_installs.
        $count = $this->pdo->prepare('SELECT COUNT(*) AS c FROM installs WHERE license_id = :lid');
        $count->execute([':lid' => $license['id']]);
        $c = (int) $count->fetch()['c'];
        if ($c >= (int) $license['max_installs']) {
            return false;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO installs (license_id, site_hash, site_url, plugin_version, first_seen, last_seen)
             VALUES (:lid, :sh, :url, :pv, :now, :now)'
        );
        $ins->execute([
            ':lid' => $license['id'],
            ':sh'  => $sitehash,
            ':url' => $siteurl,
            ':pv'  => $pluginversion,
            ':now' => $now,
        ]);
        return true;
    }

    public function log_event(?int $licenseid, string $event, ?string $bundleversion = null): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usage_log (license_id, event, bundle_version, at)
             VALUES (:lid, :ev, :bv, :at)'
        );
        $stmt->execute([
            ':lid' => $licenseid,
            ':ev'  => $event,
            ':bv'  => $bundleversion,
            ':at'  => now_utc_iso(),
        ]);
    }
}
