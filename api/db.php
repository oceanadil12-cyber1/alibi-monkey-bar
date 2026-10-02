<?php
/**
 * MySQL database connection (PDO).
 *
 * Uses the 'db_*' credentials in config.php. On Namecheap shared hosting,
 * create the database in cPanel → MySQL Databases, then fill in the values.
 */
require_once __DIR__ . '/lib.php';

function db_configured(): bool
{
    $c = config();
    return ($c['db_name'] ?? '') !== '' && ($c['db_user'] ?? '') !== '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = config();
    $host = $c['db_host'] ?? 'localhost';
    $name = $c['db_name'] ?? '';
    $user = $c['db_user'] ?? '';
    $pass = $c['db_pass'] ?? '';
    $port = $c['db_port'] ?? 3306;

    if ($name === '' || $user === '') {
        $file = data_dir() . '/alibi.sqlite';
        if (!is_dir(data_dir())) @mkdir(data_dir(), 0750, true);
        $pdo = new PDO("sqlite:$file", null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        db_sqlite_schema($pdo);
        return $pdo;
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    return $pdo;
}

function db_sqlite_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, received TEXT DEFAULT (datetime('now','localtime')),
        name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT DEFAULT '', subject TEXT DEFAULT 'general',
        message TEXT NOT NULL, status TEXT DEFAULT 'new', note TEXT DEFAULT '', ip TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, signed_up TEXT DEFAULT (datetime('now','localtime')),
        email TEXT NOT NULL UNIQUE, page TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, received TEXT DEFAULT (datetime('now','localtime')),
        name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT DEFAULT '', reservation_date TEXT NOT NULL,
        reservation_time TEXT NOT NULL, guests TEXT NOT NULL, area TEXT DEFAULT '', notes TEXT DEFAULT '',
        status TEXT DEFAULT 'pending', note TEXT DEFAULT '', ip TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT DEFAULT (datetime('now','localtime')),
        number TEXT NOT NULL UNIQUE, payment TEXT DEFAULT 'cash on delivery', order_type TEXT DEFAULT 'pickup',
        table_number INTEGER, name TEXT DEFAULT '', phone TEXT DEFAULT '', email TEXT DEFAULT '',
        address TEXT DEFAULT '', order_notes TEXT DEFAULT '', items TEXT NOT NULL, subtotal INTEGER DEFAULT 0,
        delivery_fee INTEGER DEFAULT 0, total INTEGER DEFAULT 0, status TEXT DEFAULT 'new',
        note TEXT DEFAULT '', status_history TEXT, kds_started TEXT, kds_duration INTEGER,
        kds_completed TEXT, ip TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cid TEXT NOT NULL UNIQUE,
        started_at TEXT DEFAULT (datetime('now','localtime')), ended_at TEXT,
        name TEXT DEFAULT '', email TEXT DEFAULT '', ip TEXT DEFAULT '',
        device TEXT DEFAULT '', browser TEXT DEFAULT '', flagged INTEGER DEFAULT 0, note TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL,
        sent_at TEXT DEFAULT (datetime('now','localtime')), question TEXT NOT NULL, reply TEXT NOT NULL,
        ordered TEXT, shows TEXT, provider TEXT DEFAULT '', model TEXT DEFAULT '',
        input_tokens INTEGER DEFAULT 0, cache_read INTEGER DEFAULT 0, output_tokens INTEGER DEFAULT 0,
        seconds REAL DEFAULT 0, FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT, logged_at TEXT DEFAULT (datetime('now','localtime')),
        action TEXT NOT NULL, detail TEXT DEFAULT '', ip TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL,
        updated_at TEXT DEFAULT (datetime('now','localtime')))");
}

/** Insert a row; returns the auto-increment id. */
function db_insert(string $table, array $data): int
{
    $cols = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $stmt = db()->prepare("INSERT INTO `$table` ($cols) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return (int) db()->lastInsertId();
}

/** Update rows matching $where; returns the number of affected rows. */
function db_update(string $table, array $data, array $where): int
{
    $setParts = [];
    $values = [];
    foreach ($data as $col => $val) {
        $setParts[] = "`$col` = ?";
        $values[] = $val;
    }
    $whereParts = [];
    foreach ($where as $col => $val) {
        $whereParts[] = "`$col` = ?";
        $values[] = $val;
    }
    $sql = "UPDATE `$table` SET " . implode(', ', $setParts)
         . " WHERE " . implode(' AND ', $whereParts);
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
    return $stmt->rowCount();
}

/** Read a JSON setting from the settings table; returns the decoded value or $default. */
function db_setting(string $key, $default = null)
{
    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    $decoded = json_decode($row['setting_value'], true);
    return $decoded !== null ? $decoded : $default;
}

function db_is_sqlite(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/** Write a JSON setting to the settings table (insert or update). */
function db_setting_save(string $key, $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (db_is_sqlite()) {
        $stmt = db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
    } else {
        $stmt = db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }
    $stmt->execute([$key, $json]);
}
