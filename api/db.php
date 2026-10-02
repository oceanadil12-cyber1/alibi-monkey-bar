<?php
/**
 * MySQL database connection (PDO).
 *
 * Uses the 'db_*' credentials in config.php. On Namecheap shared hosting,
 * create the database in cPanel → MySQL Databases, then fill in the values.
 */
require_once __DIR__ . '/lib.php';

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
        throw new RuntimeException(
            'Database not configured. Add db_host, db_name, db_user and db_pass to api/config.php.'
        );
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

/** Write a JSON setting to the settings table (insert or update). */
function db_setting_save(string $key, $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = db()->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $json]);
}
