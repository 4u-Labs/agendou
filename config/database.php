<?php
// ========================================================
// AGENDOU - Database Connection Singleton
// ========================================================

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = require __DIR__ . '/config.php';
        $dbConfig = $config['database'];

        try {
            if ($dbConfig['driver'] === 'sqlite') {
                $dbPath = $dbConfig['sqlite_path'];
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                $isNew = !file_exists($dbPath);

                self::$instance = new PDO("sqlite:" . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Enable WAL mode for high concurrent performance & foreign keys
                self::$instance->exec("PRAGMA journal_mode = WAL;");
                self::$instance->exec("PRAGMA foreign_keys = ON;");

                if ($isNew) {
                    self::runMigrations(self::$instance);
                } else {
                    self::ensureSchemaUpdates(self::$instance);
                }
            } else {
                // MySQL fallback
                $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
                self::$instance = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['charset']}"
                ]);
            }

            return self::$instance;
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new RuntimeException("Erro ao conectar ao banco de dados: " . $e->getMessage());
        }
    }

    public static function runMigrations(PDO $pdo): void {
        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
        }
        self::ensureSchemaUpdates($pdo);
    }

    public static function ensureSchemaUpdates(PDO $pdo): void {
        try {
            $cols = $pdo->query("PRAGMA table_info(tenants)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('monthly_price', $cols)) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN monthly_price DECIMAL(10,2) DEFAULT 49.90");
            }
            if (!in_array('next_due_date', $cols)) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN next_due_date DATE DEFAULT NULL");
                $pdo->exec("UPDATE tenants SET next_due_date = date('now', '+15 days') WHERE next_due_date IS NULL");
            }
            if (!in_array('subscription_status', $cols)) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN subscription_status TEXT DEFAULT 'active'");
            }
            if (!in_array('pix_key', $cols)) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN pix_key TEXT DEFAULT 'contato@4u.ia.br'");
            }
            if (!in_array('blocked_reason', $cols)) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN blocked_reason TEXT DEFAULT ''");
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS invoices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    due_date DATE NOT NULL,
                    status TEXT DEFAULT 'pending',
                    paid_at DATETIME NULL,
                    payment_method TEXT DEFAULT 'pix',
                    mp_id TEXT DEFAULT NULL,
                    reference_month TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
                )
            ");

            $invCols = $pdo->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('mp_id', $invCols)) {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN mp_id TEXT DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log("Schema update notice: " . $e->getMessage());
        }
    }
}
