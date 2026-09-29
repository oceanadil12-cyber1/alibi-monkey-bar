<?php
/**
 * Where the admin panel reads and writes the team's records.
 *
 * The website already saves what guests send it: orders (data/orders/DAY.jsonl),
 * reservations, contact messages and newsletter sign-ups (data/*.csv) and chats
 * (data/chats/DAY.jsonl). Those files are only read here (a newsletter removal or
 * a deleted message is the one exception). What the team adds — an order's
 * status, a note on a reservation, a follow-up flag on a chat — goes in one small
 * side file, data/admin-meta.json, keyed by record; every change is also noted in
 * data/admin-activity.jsonl.
 */
if (!defined('ADMIN') && !defined('KDS')) { http_response_code(403); exit('Forbidden'); }

// status key => [label, tone]. The first key is what a record has until someone changes it.
const ORDER_STATUSES = [
    'new'       => ['New',       'info'],
    'preparing' => ['Preparing', 'warn'],
    'ready'     => ['Ready',     'good'],
    'completed' => ['Completed', 'muted'],
    'cancelled' => ['Cancelled', 'crit'],
];
const RESERVATION_STATUSES = [
    'pending'   => ['Pending',   'warn'],
    'confirmed' => ['Confirmed', 'good'],
    'declined'  => ['Declined',  'crit'],
    'cancelled' => ['Cancelled', 'muted'],
];
const MESSAGE_STATUSES = [
    'new'      => ['New',      'info'],
    'read'     => ['Read',     'muted'],
    'replied'  => ['Replied',  'good'],
    'archived' => ['Archived', 'muted'],
];

// ---------- small JSON files, safe against two people saving at once ----------

/** A JSON file as an array ([] if missing or unreadable). */
function json_read_file(string $file): array
{
    $fh = is_file($file) ? @fopen($file, 'r') : false;
    if (!$fh) return [];
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/** Read, change and save a JSON file under one lock: $change(array $data): array. */
function json_update_file(string $file, callable $change): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $fh = @fopen($file, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true);
    $data = $change(is_array($data) ? $data : []);
    ftruncate($fh, 0);
    rewind($fh);
    $ok = fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

// ---------- statuses, notes and flags ----------

function admin_meta_file(): string
{
    return data_dir() . '/admin-meta.json';
}

/** Everything the team has added, as [kind => [id => fields]]; kind is order, reservation, message or chat. */
function meta_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) $cache = json_read_file(admin_meta_file());
    return $cache;
}

function meta_get(string $kind, string $id): array
{
    return meta_all()[$kind][$id] ?? [];
}

/** Change one record's fields: $change(array $fields): array; returning [] forgets the record. */
function meta_update(string $kind, string $id, callable $change): void
{
    json_update_file(admin_meta_file(), function (array $all) use ($kind, $id, $change) {
        $entry = $change($all[$kind][$id] ?? []);
        if ($entry) $all[$kind][$id] = $entry; else unset($all[$kind][$id]);
        if (empty($all[$kind])) unset($all[$kind]);
        return $all;
    });
    meta_all(true);
}

/** Set fields on a record; null, '' or false removes that field. */
function meta_set(string $kind, string $id, array $fields): void
{
    meta_update($kind, $id, function (array $e) use ($fields) {
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '' || $v === false) unset($e[$k]); else $e[$k] = $v;
        }
        return $e;
    });
}

function meta_forget(string $kind, string $id): void
{
    meta_update($kind, $id, fn(array $e) => []);
}

/** Set a record's status, keeping a short history of when it changed (used for orders). */
function meta_set_status(string $kind, string $id, string $status): void
{
    meta_update($kind, $id, function (array $e) use ($status) {
        if (($e['status'] ?? '') === $status) return $e;
        $e['status'] = $status;
        $e['history'] = array_slice(array_merge((array) ($e['history'] ?? []), [['s' => $status, 't' => date('c')]]), -20);
        return $e;
    });
}

// ---------- orders ----------

function orders_dir(): string
{
    return data_dir() . '/orders';
}

/** One saved order plus what the team added (status, note, history), with tidy types. */
function order_with_meta(array $o, array $meta): array
{
    $m = $meta[$o['number']] ?? [];
    $o['ts'] = (int) strtotime((string) ($o['time'] ?? ''));
    $o['status'] = isset(ORDER_STATUSES[$m['status'] ?? '']) ? $m['status'] : 'new';
    $o['note'] = (string) ($m['note'] ?? '');
    $o['history'] = (array) ($m['history'] ?? []);
    $o['items'] = array_values(array_map('strval', (array) ($o['items'] ?? [])));
    foreach (['subtotal', 'deliveryFee', 'total'] as $k) $o[$k] = (int) ($o[$k] ?? 0);
    foreach (['number', 'name', 'phone', 'email', 'address', 'type', 'payment'] as $k) $o[$k] = (string) ($o[$k] ?? '');
    if (isset($o['table'])) $o['table'] = (int) $o['table'];
    if (isset($o['notes'])) $o['notes'] = (string) $o['notes'];
    return $o;
}

/** Orders saved from $from to $to (Y-m-d, inclusive), newest first. */
function orders_between(string $from, string $to): array
{
    static $memo = [];
    if (isset($memo["$from|$to"])) return $memo["$from|$to"];
    $files = glob(orders_dir() . '/*.jsonl') ?: [];
    sort($files);
    $meta = meta_all()['order'] ?? [];
    $out = [];
    foreach ($files as $file) {
        $day = basename($file, '.jsonl');
        if ($day < $from || $day > $to) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $o = json_decode($line, true);
            if (is_array($o) && !empty($o['number'])) $out[] = order_with_meta($o, $meta);
        }
    }
    usort($out, fn($a, $b) => [$b['ts'], $b['number']] <=> [$a['ts'], $a['number']]);
    return $memo["$from|$to"] = $out;
}

/** The last $days days of orders, today included. */
function orders_recent(int $days): array
{
    return orders_between(date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days')), date('Y-m-d'));
}

/** One order by its six-character number, from any day; null if not found. */
function order_find(string $number): ?array
{
    $number = strtoupper($number);
    if (!preg_match('/^[A-F0-9]{6}$/', $number)) return null;
    $files = glob(orders_dir() . '/*.jsonl') ?: [];
    rsort($files);
    foreach ($files as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (strpos($line, '"' . $number . '"') === false) continue;
            $o = json_decode($line, true);
            if (is_array($o) && ($o['number'] ?? '') === $number) return order_with_meta($o, meta_all()['order'] ?? []);
        }
    }
    return null;
}

/**
 * An order's item lines as saved by checkout ("2 x Wings (10) [BBQ] - note: x = $18.00"),
 * split into [['qty', 'label', 'mods', 'note', 'total' (cents)], ...]. A line that
 * doesn't fit that shape is kept whole.
 */
function order_lines(array $o): array
{
    $out = [];
    foreach ($o['items'] as $s) {
        $line = ['qty' => 1, 'label' => $s, 'mods' => '', 'note' => '', 'total' => null];
        if (preg_match('/^(\d+) x (.*) = \$([\d,]+(?:\.\d{2})?)$/su', $s, $m)) {
            $rest = $m[2];
            $line['qty'] = (int) $m[1];
            $line['total'] = (int) round(((float) str_replace(',', '', $m[3])) * 100);
            if (($p = strpos($rest, ' - note: ')) !== false) {
                $line['note'] = substr($rest, $p + 9);
                $rest = substr($rest, 0, $p);
            }
            if (preg_match('/^(.*) \[(.*)\]$/su', $rest, $mm)) {
                $line['mods'] = $mm[2];
                $rest = $mm[1];
            }
            $line['label'] = $rest;
        }
        $out[] = $line;
    }
    return $out;
}

// ---------- reservations, contact messages, newsletter (the CSVs) ----------

/** The raw rows of data/NAME.csv as [header, [assoc row, ...]]; empty if the file doesn't exist. */
function csv_read_raw(string $name): array
{
    $path = data_dir() . "/$name.csv";
    $fh = is_file($path) ? @fopen($path, 'r') : false;
    if (!$fh) return [[], []];
    flock($fh, LOCK_SH);
    $header = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    if (is_array($header)) {
        $n = count($header);
        while (($f = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($f === [null]) continue; // blank line
            $rows[] = array_combine($header, array_slice(array_pad($f, $n, ''), 0, $n));
        }
    } else {
        $header = [];
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return [$header, $rows];
}

/** save_row() prefixes ' to values that start like a spreadsheet formula; take it off for display. */
function csv_plain(array $row): array
{
    foreach ($row as $k => $v) $row[$k] = preg_replace("/^'(?=[=+\\-@\\t\\r])/", '', (string) $v);
    return $row;
}

/** The rows of data/NAME.csv, ready to display. */
function csv_rows(string $name): array
{
    return array_map('csv_plain', csv_read_raw($name)[1]);
}

/** Rewrite data/NAME.csv keeping the rows for which $keep(displayRow) is true; returns how many were dropped. */
function csv_rewrite(string $name, callable $keep): int
{
    $path = data_dir() . "/$name.csv";
    $fh = is_file($path) ? @fopen($path, 'c+') : false;
    if (!$fh) return 0;
    flock($fh, LOCK_EX);
    $header = fgetcsv($fh, 0, ',', '"', '');
    $kept = [];
    $dropped = 0;
    if (is_array($header)) {
        $n = count($header);
        while (($f = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($f === [null]) continue;
            $raw = array_combine($header, array_slice(array_pad($f, $n, ''), 0, $n));
            if ($keep(csv_plain($raw))) $kept[] = $raw; else $dropped++;
        }
        if ($dropped) {
            ftruncate($fh, 0);
            rewind($fh);
            fputcsv($fh, $header, ',', '"', '');
            foreach ($kept as $raw) fputcsv($fh, array_values($raw), ',', '"', '');
            fflush($fh);
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $dropped;
}

/** A short stable id for a CSV row (they have none), from the fields that identify it. */
function csv_row_id(array $row, array $keys): string
{
    $parts = [];
    foreach ($keys as $k) $parts[] = (string) ($row[$k] ?? '');
    return substr(sha1(implode("\x1f", $parts)), 0, 12);
}

// The seating areas offered on contact.html.
const RESERVATION_AREAS = ['main-bar' => 'Main Bar', 'monkey-bar' => 'Monkey Bar', 'patio' => 'Outdoor Patio'];

function reservation_area(string $area): string
{
    return RESERVATION_AREAS[$area] ?? ($area !== '' ? $area : 'No preference');
}

/** "4 guests"; the form's top choice ("8") means eight or more. */
function reservation_party(string $guests): string
{
    return $guests === '8' ? '8+ guests' : ($guests === '1' ? '1 guest' : $guests . ' guests');
}

const RESERVATION_ID_FIELDS = ['received', 'name', 'email', 'phone', 'date', 'time', 'guests'];
const MESSAGE_ID_FIELDS = ['received', 'name', 'email', 'subject', 'message'];

/** Reservation requests, newest first, with id, status, note, ts (received) and at (the booking time). */
function reservations_all(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    if ($reload) meta_all(true);
    $meta = meta_all()['reservation'] ?? [];
    $out = [];
    foreach (csv_rows('reservations') as $r) {
        $r += ['received' => '', 'name' => '', 'email' => '', 'phone' => '', 'date' => '', 'time' => '', 'guests' => '', 'area' => '', 'notes' => ''];
        $r['id'] = csv_row_id($r, RESERVATION_ID_FIELDS);
        $m = $meta[$r['id']] ?? [];
        $r['status'] = isset(RESERVATION_STATUSES[$m['status'] ?? '']) ? $m['status'] : 'pending';
        $r['note'] = (string) ($m['note'] ?? '');
        $r['ts'] = (int) strtotime($r['received']);
        $r['at'] = (int) strtotime($r['date'] . ' ' . (preg_match('/^\d{1,2}:\d{2}$/', $r['time']) ? $r['time'] : '00:00'));
        $out[] = $r;
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $memo = $out;
}

/** Contact-form messages, newest first, with id, status, note and ts. */
function messages_all(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    if ($reload) meta_all(true);
    $meta = meta_all()['message'] ?? [];
    $out = [];
    foreach (csv_rows('contact') as $r) {
        $r += ['received' => '', 'name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];
        $r['id'] = csv_row_id($r, MESSAGE_ID_FIELDS);
        $m = $meta[$r['id']] ?? [];
        $r['status'] = isset(MESSAGE_STATUSES[$m['status'] ?? '']) ? $m['status'] : 'new';
        $r['note'] = (string) ($m['note'] ?? '');
        $r['ts'] = (int) strtotime($r['received']);
        $out[] = $r;
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $memo = $out;
}

/** Newsletter sign-ups, one per email (earliest sign-up wins), newest first. */
function subscribers_all(): array
{
    $seen = [];
    foreach (csv_rows('newsletter') as $r) {
        $email = strtolower(trim((string) ($r['email'] ?? '')));
        if ($email === '') continue;
        $ts = (int) strtotime((string) ($r['signed_up'] ?? ''));
        if (!isset($seen[$email])) {
            $seen[$email] = ['email' => $email, 'ts' => $ts, 'page' => (string) ($r['page'] ?? ''), 'times' => 1];
        } else {
            $seen[$email]['times']++;
            if ($ts && $ts < $seen[$email]['ts']) { $seen[$email]['ts'] = $ts; $seen[$email]['page'] = (string) ($r['page'] ?? ''); }
        }
    }
    $list = array_values($seen);
    usort($list, fn($a, $b) => [$b['ts'], $a['email']] <=> [$a['ts'], $b['email']]);
    return $list;
}

// ---------- what needs attention ----------

/** Numbers for the sidebar badges: new orders, upcoming reservations waiting for an answer, unread messages. */
function admin_counts(): array
{
    $orders = 0;
    foreach (orders_recent(14) as $o) if ($o['status'] === 'new') $orders++;
    $today = date('Y-m-d');
    $reservations = 0;
    foreach (reservations_all() as $r) if ($r['status'] === 'pending' && $r['date'] >= $today) $reservations++;
    $messages = 0;
    foreach (messages_all() as $m) if ($m['status'] === 'new') $messages++;
    return ['orders' => $orders, 'reservations' => $reservations, 'messages' => $messages];
}

// ---------- the business's own details ----------

/** Name, address and phone as set in Store settings (with the usual fallbacks), for email templates and print-outs. */
function admin_store(): array
{
    static $store = null;
    if ($store === null) {
        $menu = load_menu();
        $store = array_filter((array) ($menu['store'] ?? []), 'is_scalar') + [
            'name' => "Georgie's Alibi Monkey Bar", 'address' => '2266 Wilton Drive, Wilton Manors, FL 33305', 'phone' => '(954) 565-2526',
        ];
    }
    return $store;
}

// ---------- activity log ----------

// action code => [label, tone] for the Activity page and the dashboard.
const ADMIN_ACTIONS = [
    'login'              => ['Signed in', 'muted'],
    'login.failed'       => ['Wrong password', 'crit'],
    'logout'             => ['Signed out', 'muted'],
    'order.status'       => ['Order status changed', 'gold'],
    'order.note'         => ['Order note saved', 'muted'],
    'reservation.status' => ['Reservation updated', 'gold'],
    'reservation.note'   => ['Reservation note saved', 'muted'],
    'message.status'     => ['Message updated', 'gold'],
    'message.note'       => ['Message note saved', 'muted'],
    'message.delete'     => ['Message deleted', 'crit'],
    'subscriber.remove'  => ['Subscriber removed', 'crit'],
    'chat.flag'          => ['Chat flagged', 'gold'],
    'chat.note'          => ['Chat note saved', 'muted'],
    'menu.items'         => ['Menu saved', 'gold'],
    'menu.add'           => ['Menu item added', 'gold'],
    'menu.custom'        => ['Added items saved', 'gold'],
    'menu.item'          => ['Item options saved', 'gold'],
    'event.save'         => ['Event saved', 'gold'],
    'event.delete'       => ['Event deleted', 'crit'],
    'event.publish'      => ['Events published', 'good'],
    'event.import'       => ['Events loaded from the page', 'muted'],
    'event.restore'      => ['Events page restored', 'gold'],
    'happy.save'         => ['Happy Hour saved', 'gold'],
    'settings.save'      => ['Store settings saved', 'gold'],
    'knowledge.save'     => ['Chat knowledge saved', 'gold'],
    'knowledge.restore'  => ['Chat knowledge restored', 'gold'],
    'order.dine-in'      => ['Dine-in order created', 'gold'],
    'kds.login'          => ['KDS signed in', 'muted'],
    'kds.login.failed'   => ['KDS wrong password', 'crit'],
    'kds.start'          => ['KDS order started', 'gold'],
    'kds.complete'       => ['KDS order completed', 'good'],
    'kds.recall'         => ['KDS order recalled', 'warn'],
    'kds.settings'       => ['KDS settings saved', 'gold'],
];

function admin_action_label(string $code): array
{
    return ADMIN_ACTIONS[$code] ?? [$code, 'muted'];
}

function admin_activity_file(): string
{
    return data_dir() . '/admin-activity.jsonl';
}

/** Note what someone did in the admin: $action is a short code (see the Activity page), $detail plain text. */
function admin_log(string $action, string $detail = ''): void
{
    if (!is_dir(data_dir())) @mkdir(data_dir(), 0750, true);
    $file = admin_activity_file();
    $row = ['time' => date('c'), 'action' => $action, 'detail' => mb_substr($detail, 0, 300), 'ip' => client_ip()];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (mt_rand(1, 40) === 1 && @filesize($file) > 524288) { // keep the newest 1,500 entries
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @file_put_contents($file, implode("\n", array_slice($lines, -1500)) . "\n", LOCK_EX);
    }
}

/** The newest $limit activity entries, newest first. */
function admin_activity(int $limit = 100): array
{
    $lines = is_file(admin_activity_file()) ? (file(admin_activity_file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $out = [];
    foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) $out[] = $row + ['time' => '', 'action' => '', 'detail' => '', 'ip' => ''];
    }
    return $out;
}
