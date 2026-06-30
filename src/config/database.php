<?php
/**
 * Database connection + auto-migration layer.
 * Supports SQLite (default) and MySQL via environment variables.
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    // ── Connection ───────────────────────────────────────────────────────────
    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $type = getenv('DB_TYPE') ?: 'sqlite';

        if ($type === 'mysql') {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $name = getenv('DB_NAME') ?: 'owntracks';
            $user = getenv('DB_USER') ?: 'owntracks';
            $pass = getenv('DB_PASS') ?: '';
            $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        } else {
            // SQLite
            $dbPath = getenv('DB_PATH') ?: '/app/data/owntracks.db';
            $dsn = "sqlite:{$dbPath}";
        }

        self::$pdo = new PDO($dsn, $user ?? null, $pass ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Enable WAL mode for SQLite (concurrent reads + writes)
        if ($type === 'sqlite') {
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA busy_timeout=5000');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        }

        self::migrate();
        return self::$pdo;
    }

    // ── Auto-migration ──────────────────────────────────────────────────────
    public static function migrate(): void
    {
        $pdo       = self::$pdo;
        $type      = getenv('DB_TYPE') ?: 'sqlite';
        $isMySQL   = $type === 'mysql';
        $autoIncrement = $isMySQL ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';

        // ── users ────────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY {$autoIncrement},
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // ── devices ──────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS devices (
                id INTEGER PRIMARY KEY {$autoIncrement},
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                tid TEXT NOT NULL,
                webhook_token TEXT NOT NULL,
                config_json TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // ── locations ────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS locations (
                id INTEGER PRIMARY KEY {$autoIncrement},
                device_id INTEGER NOT NULL,
                lat REAL,
                lon REAL,
                tst INTEGER NOT NULL,
                acc REAL,
                alt REAL,
                vac REAL,
                vel REAL,
                batt INTEGER,
                bs TEXT,
                conn TEXT,
                t TEXT,
                tag TEXT,
                raw_data TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            )
        ");

        // ── Index: (device_id, tst) for fast location queries ────────────────
        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_locations_device_tst
            ON locations (device_id, tst)
        ");

        self::addColumnIfMissing('devices', 'color', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing('locations', 'poi', "TEXT DEFAULT NULL");
        self::addColumnIfMissing('locations', 'poi_imagename', "TEXT DEFAULT NULL");

        // ── events_log ───────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS events_log (
                id INTEGER PRIMARY KEY {$autoIncrement},
                device_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                tst INTEGER NOT NULL,
                raw_data TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_events_log_device_tst
            ON events_log (device_id, tst)
        ");
    }

    /** Add a column to a table if it doesn't already exist */
    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $pdo = self::$pdo;
        $type = getenv('DB_TYPE') ?: 'sqlite';

        if ($type === 'mysql') {
            $result = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if ($result && $result->fetch()) return;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } else {
            $result = $pdo->query("PRAGMA table_info(`{$table}`)");
            foreach ($result->fetchAll() as $row) {
                if (($row['name'] ?? '') === $column) return;
            }
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    public static function raw(): PDO
    {
        return self::connect();
    }

    /** Run a prepared query with params, return all rows */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a prepared query, return single row or null */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /** Execute a statement, return number of affected rows */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Insert and return last insert ID */
    public static function insert(string $sql, array $params = []): string
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return self::$pdo->lastInsertId();
    }
}

// ── Bootstrap auto-connect ───────────────────────────────────────────────────
// Session: start PHP session for local auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auto-connect on first load (triggers migrations)
Database::connect();
