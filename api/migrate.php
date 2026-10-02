<?php
/**
 * One-time migration: flat files (CSV / JSONL / JSON) → MySQL.
 *
 * Run from the command line AFTER setup.sql has created the tables:
 *   php api/migrate.php
 *
 * Safe to re-run: uses INSERT IGNORE / ON DUPLICATE KEY so existing rows are
 * skipped. Prints a summary of what was imported.
 */
date_default_timezone_set('America/New_York');

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

$dataDir = data_dir();
$counts  = [];

function note(string $msg): void { echo "  $msg\n"; }

// ── helpers ──────────────────────────────────────────────────────────────

function csv_read(string $path): array
{
    if (!is_file($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh) return [];
    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!is_array($header)) { fclose($fh); return []; }
    $n = count($header);
    $rows = [];
    while (($f = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($f === [null]) continue;
        $row = array_combine($header, array_slice(array_pad($f, $n, ''), 0, $n));
        foreach ($row as $k => $v) $row[$k] = preg_replace("/^'(?=[=+\\-@\\t\\r])/", '', (string) $v);
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function jsonl_read(string $path): array
{
    if (!is_file($path)) return [];
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

function json_read(string $path): array
{
    if (!is_file($path)) return [];
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// Hash function matching the old csv_row_id().
function row_hash(array $row, array $keys): string
{
    $parts = [];
    foreach ($keys as $k) $parts[] = (string) ($row[$k] ?? '');
    return substr(sha1(implode("\x1f", $parts)), 0, 12);
}

// ── 1. Contact messages (data/contact.csv) ───────────────────────────────

echo "\n=== Contact messages ===\n";
$contactRows = csv_read("$dataDir/contact.csv");
$contactHashMap = []; // old hash → DB auto-increment id
$n = 0;
foreach ($contactRows as $r) {
    $r += ['received' => '', 'name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];
    $hash = row_hash($r, ['received', 'name', 'email', 'subject', 'message']);
    try {
        $stmt = db()->prepare("INSERT IGNORE INTO contacts (received, name, email, phone, subject, message)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $r['received'] ?: date('c'),
            $r['name'], $r['email'], $r['phone'], $r['subject'], $r['message'],
        ]);
        if ($stmt->rowCount()) {
            $contactHashMap[$hash] = (int) db()->lastInsertId();
            $n++;
        }
    } catch (Throwable $e) { note("SKIP contact: " . $e->getMessage()); }
}
$counts['contacts'] = $n;
note("$n contact messages imported.");

// ── 2. Newsletter subscribers (data/newsletter.csv) ──────────────────────

echo "\n=== Newsletter subscribers ===\n";
$rows = csv_read("$dataDir/newsletter.csv");
$n = 0;
foreach ($rows as $r) {
    $r += ['signed_up' => '', 'email' => '', 'page' => ''];
    $email = strtolower(trim($r['email']));
    if ($email === '') continue;
    try {
        $stmt = db()->prepare("INSERT INTO subscribers (signed_up, email, page)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE page = page");
        $stmt->execute([$r['signed_up'] ?: date('c'), $email, $r['page']]);
        if ($stmt->rowCount()) $n++;
    } catch (Throwable $e) { note("SKIP subscriber: " . $e->getMessage()); }
}
$counts['subscribers'] = $n;
note("$n subscribers imported.");

// ── 3. Reservations (data/reservations.csv) ──────────────────────────────

echo "\n=== Reservations ===\n";
$reservationRows = csv_read("$dataDir/reservations.csv");
$reservationHashMap = []; // old hash → DB auto-increment id
$n = 0;
foreach ($reservationRows as $r) {
    $r += ['received' => '', 'name' => '', 'email' => '', 'phone' => '', 'date' => '', 'time' => '', 'guests' => '', 'area' => '', 'notes' => ''];
    $hash = row_hash($r, ['received', 'name', 'email', 'phone', 'date', 'time', 'guests']);
    try {
        $stmt = db()->prepare("INSERT IGNORE INTO reservations
            (received, name, email, phone, reservation_date, reservation_time, guests, area, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $r['received'] ?: date('c'),
            $r['name'], $r['email'], $r['phone'],
            $r['date'], $r['time'], $r['guests'], $r['area'], $r['notes'],
        ]);
        if ($stmt->rowCount()) {
            $reservationHashMap[$hash] = (int) db()->lastInsertId();
            $n++;
        }
    } catch (Throwable $e) { note("SKIP reservation: " . $e->getMessage()); }
}
$counts['reservations'] = $n;
note("$n reservations imported.");

// ── 4. Orders (data/orders/*.jsonl) ──────────────────────────────────────

echo "\n=== Orders ===\n";
$ordersDir = "$dataDir/orders";
$n = 0;
if (is_dir($ordersDir)) {
    $files = glob("$ordersDir/*.jsonl") ?: [];
    sort($files);
    foreach ($files as $file) {
        foreach (jsonl_read($file) as $o) {
            $o += ['time' => '', 'number' => '', 'payment' => '', 'type' => 'pickup',
                   'table' => null, 'name' => '', 'phone' => '', 'email' => '', 'address' => '',
                   'items' => [], 'notes' => '', 'subtotal' => 0, 'deliveryFee' => 0, 'total' => 0];
            if ($o['number'] === '') continue;
            try {
                $stmt = db()->prepare("INSERT IGNORE INTO orders
                    (created_at, number, payment, order_type, table_number, name, phone, email, address,
                     order_notes, items, subtotal, delivery_fee, total)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $o['time'] ?: date('c'),
                    strtoupper($o['number']),
                    $o['payment'],
                    $o['type'],
                    $o['table'] !== null && $o['table'] !== '' ? (int) $o['table'] : null,
                    $o['name'], $o['phone'], $o['email'], $o['address'],
                    $o['notes'] ?? '',
                    json_encode(is_array($o['items']) ? $o['items'] : [], JSON_UNESCAPED_UNICODE),
                    (int) $o['subtotal'],
                    (int) $o['deliveryFee'],
                    (int) $o['total'],
                ]);
                if ($stmt->rowCount()) $n++;
            } catch (Throwable $e) { note("SKIP order {$o['number']}: " . $e->getMessage()); }
        }
    }
}
$counts['orders'] = $n;
note("$n orders imported.");

// ── 5. Chat sessions and messages (data/chats/*.jsonl) ───────────────────

echo "\n=== Chat conversations ===\n";
$chatsDir = "$dataDir/chats";
$sessions = 0;
$messages = 0;
if (is_dir($chatsDir)) {
    $files = glob("$chatsDir/*.jsonl") ?: [];
    sort($files);
    $cidMap = []; // cid => session DB id
    foreach ($files as $file) {
        foreach (jsonl_read($file) as $row) {
            $row += ['time' => '', 'cid' => 'unknown', 'name' => '', 'email' => '',
                     'ip' => '', 'device' => '', 'browser' => '',
                     'question' => '', 'reply' => '', 'ordered' => [], 'shows' => [],
                     'provider' => '', 'model' => '', 'input_tokens' => 0,
                     'cache_read' => 0, 'output_tokens' => 0, 'seconds' => 0];
            $cid = $row['cid'];

            if (!isset($cidMap[$cid])) {
                try {
                    $stmt = db()->prepare("INSERT INTO chat_sessions (cid, started_at, name, email, ip, device, browser)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                            name = IF(name = '', VALUES(name), name),
                            email = IF(email = '', VALUES(email), email)");
                    $stmt->execute([$cid, $row['time'] ?: date('c'),
                        $row['name'], $row['email'], $row['ip'], $row['device'], $row['browser']]);
                    $cidMap[$cid] = (int) db()->lastInsertId();
                    $sessions++;
                } catch (Throwable $e) {
                    note("SKIP chat session $cid: " . $e->getMessage());
                    continue;
                }
            }

            $sessionId = $cidMap[$cid];
            try {
                $stmt = db()->prepare("INSERT INTO chat_messages
                    (session_id, sent_at, question, reply, ordered, shows, provider, model,
                     input_tokens, cache_read, output_tokens, seconds)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $sessionId,
                    $row['time'] ?: date('c'),
                    $row['question'], $row['reply'],
                    !empty($row['ordered']) ? json_encode($row['ordered']) : null,
                    !empty($row['shows']) ? json_encode($row['shows']) : null,
                    $row['provider'], $row['model'],
                    (int) $row['input_tokens'], (int) $row['cache_read'],
                    (int) $row['output_tokens'], round((float) $row['seconds'], 1),
                ]);
                $messages++;
            } catch (Throwable $e) { note("SKIP chat message: " . $e->getMessage()); }
        }
    }
    // Update ended_at on each session to the last message time.
    foreach ($cidMap as $cid => $sid) {
        db()->prepare("UPDATE chat_sessions SET ended_at = (SELECT MAX(sent_at) FROM chat_messages WHERE session_id = ?) WHERE id = ?")
            ->execute([$sid, $sid]);
    }
}
$counts['chat_sessions'] = $sessions;
$counts['chat_messages'] = $messages;
note("$sessions sessions, $messages messages imported.");

// ── 6. Admin activity log (data/admin-activity.jsonl) ────────────────────

echo "\n=== Admin activity ===\n";
$rows = jsonl_read("$dataDir/admin-activity.jsonl");
$n = 0;
foreach ($rows as $r) {
    $r += ['time' => '', 'action' => '', 'detail' => '', 'ip' => ''];
    try {
        $stmt = db()->prepare("INSERT INTO admin_activity (logged_at, action, detail, ip) VALUES (?, ?, ?, ?)");
        $stmt->execute([$r['time'] ?: date('c'), $r['action'], mb_substr($r['detail'], 0, 300), $r['ip']]);
        $n++;
    } catch (Throwable $e) { note("SKIP activity: " . $e->getMessage()); }
}
$counts['admin_activity'] = $n;
note("$n activity entries imported.");

// ── 7. Admin metadata (data/admin-meta.json) ─────────────────────────────
// status, note, flagged fields → update the rows already inserted above.

echo "\n=== Admin metadata (statuses, notes, flags) ===\n";
$meta = json_read("$dataDir/admin-meta.json");
$applied = 0;

// Hash maps were built during import (steps 1 and 3) from the original CSV
// values, so they match the hashes stored in admin-meta.json exactly.

// Orders: keyed by order number in meta.
if (!empty($meta['order'])) {
    foreach ($meta['order'] as $number => $m) {
        $data = [];
        if (!empty($m['status'])) $data['status'] = $m['status'];
        if (!empty($m['note'])) $data['note'] = $m['note'];
        if (!empty($m['history'])) $data['status_history'] = json_encode($m['history']);
        if (!empty($m['kds_started'])) $data['kds_started'] = $m['kds_started'];
        if (isset($m['kds_duration'])) $data['kds_duration'] = (int) $m['kds_duration'];
        if (!empty($m['kds_completed'])) $data['kds_completed'] = $m['kds_completed'];
        if ($data) {
            try {
                db_update('orders', $data, ['number' => strtoupper($number)]);
                $applied++;
            } catch (Throwable $e) { note("SKIP order meta $number: " . $e->getMessage()); }
        }
    }
}

// Reservations: keyed by hash.
if (!empty($meta['reservation'])) {
    foreach ($meta['reservation'] as $hash => $m) {
        $dbId = $reservationHashMap[$hash] ?? null;
        if (!$dbId) { note("SKIP reservation meta $hash: no matching row"); continue; }
        $data = [];
        if (!empty($m['status'])) $data['status'] = $m['status'];
        if (!empty($m['note'])) $data['note'] = $m['note'];
        if ($data) {
            try { db_update('reservations', $data, ['id' => $dbId]); $applied++; }
            catch (Throwable $e) { note("SKIP reservation meta $hash: " . $e->getMessage()); }
        }
    }
}

// Messages: keyed by hash.
if (!empty($meta['message'])) {
    foreach ($meta['message'] as $hash => $m) {
        $dbId = $contactHashMap[$hash] ?? null;
        if (!$dbId) { note("SKIP message meta $hash: no matching row"); continue; }
        $data = [];
        if (!empty($m['status'])) $data['status'] = $m['status'];
        if (!empty($m['note'])) $data['note'] = $m['note'];
        if ($data) {
            try { db_update('contacts', $data, ['id' => $dbId]); $applied++; }
            catch (Throwable $e) { note("SKIP message meta $hash: " . $e->getMessage()); }
        }
    }
}

// Chats: keyed by cid.
if (!empty($meta['chat'])) {
    foreach ($meta['chat'] as $cid => $m) {
        $data = [];
        if (!empty($m['flagged'])) $data['flagged'] = 1;
        if (!empty($m['note'])) $data['note'] = $m['note'];
        if ($data) {
            try { db_update('chat_sessions', $data, ['cid' => $cid]); $applied++; }
            catch (Throwable $e) { note("SKIP chat meta $cid: " . $e->getMessage()); }
        }
    }
}

$counts['meta_applied'] = $applied;
note("$applied metadata entries applied.");

// ── 8. Settings JSON files → settings table ──────────────────────────────

echo "\n=== Settings ===\n";
$settingsFiles = [
    'happy_hour'     => "$dataDir/happy-hour.json",
    'menu_overrides' => "$dataDir/menu-overrides.json",
    'store_settings' => "$dataDir/store-settings.json",
    'kds_settings'   => "$dataDir/kds-settings.json",
];
$n = 0;
foreach ($settingsFiles as $key => $path) {
    $data = json_read($path);
    if (!$data) { note("$key: no file or empty, skipped."); continue; }
    try {
        db_setting_save($key, $data);
        $n++;
        note("$key: imported.");
    } catch (Throwable $e) { note("SKIP $key: " . $e->getMessage()); }
}
$counts['settings'] = $n;

// ── Summary ──────────────────────────────────────────────────────────────

echo "\n=== Migration complete ===\n";
foreach ($counts as $k => $v) echo "  $k: $v\n";
echo "\nDone. You can now verify in phpMyAdmin and remove this script.\n";
