<?php
declare(strict_types=1);

namespace HostScope\Plugin\SqlcipherEncryptedSqlite;

/** SQLCipher adapter. The isolated runtime decides whether it may invoke this
 * class; this class itself never falls back to ordinary SQLite. */
final class SqlcipherStorageProvider
{
    /** @return array{id:string,state:string,reason:string,version?:string} */
    public function capability(): array
    {
        if (!class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return ['id' => 'sqlcipher-encrypted-sqlite', 'state' => 'unavailable', 'reason' => 'PDO SQLite is unavailable.'];
        }
        try {
            $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $version = trim((string)$pdo->query('PRAGMA cipher_version')->fetchColumn());
        } catch (\Throwable) { $version = ''; }
        if ($version === '') {
            return ['id' => 'sqlcipher-encrypted-sqlite', 'state' => 'unavailable',
                'reason' => 'The active PDO SQLite driver is not SQLCipher-capable. HostScope will not use plaintext SQLite as a substitute.'];
        }
        return ['id' => 'sqlcipher-encrypted-sqlite', 'state' => 'driver-ready', 'version' => $version,
            'reason' => 'SQLCipher was detected. Activation still requires the isolated package runtime and a verified recovery migration.'];
    }

    public function activate(): never
    {
        throw new \RuntimeException('The isolated HostScope package runtime is not installed. SQLCipher activation remains unavailable.');
    }

    /** Opens a SQLCipher database after proving the active driver exposes
     * cipher_version. `$key` is caller-owned, held only for this operation. */
    public function open(string $path, string $key): \PDO
    {
        $capability = $this->capability();
        if ($capability['state'] !== 'driver-ready') throw new \RuntimeException($capability['reason']);
        if ($path === '' || !str_starts_with($path, '/') || strlen($key) !== 32) {
            throw new \InvalidArgumentException('SQLCipher database path or 256-bit key is invalid');
        }
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory) || is_link($path)) {
            throw new \RuntimeException('SQLCipher database location is unavailable or unsafe');
        }
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec("PRAGMA key = \"x'" . bin2hex($key) . "'\"");
        $version = trim((string)$pdo->query('PRAGMA cipher_version')->fetchColumn());
        if ($version === '') throw new \RuntimeException('SQLCipher key setup was not verified; refusing plaintext fallback');
        try {
            // cipher_version proves the driver, not that this particular file
            // opened with the supplied key. Read the schema and then force
            // SQLCipher's full-page authentication check before returning it.
            $pdo->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
            $integrity = strtolower(trim((string)$pdo->query('PRAGMA cipher_integrity_check')->fetchColumn()));
            if ($integrity !== 'ok') throw new \RuntimeException('SQLCipher integrity proof was not ok');
        } catch (\Throwable $error) {
            throw new \RuntimeException('SQLCipher key or database integrity proof failed; refusing plaintext fallback', 0, $error);
        }
        $pdo->exec('PRAGMA foreign_keys = ON'); $pdo->exec('PRAGMA journal_mode = WAL'); $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }
}
