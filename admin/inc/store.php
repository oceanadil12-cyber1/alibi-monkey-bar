<?php
/**
 * Where the admin panel reads and writes the team's records (MySQL via PDO).
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

require_once __DIR__ . '/../../api/db.php';

// ---------- statuses, notes and flags ----------

function meta_get(string $kind, string $id): array
{
    return [];
}

/** Set a record's status. For orders, also updates status_history JSON. */
function meta_set_status(string $kind, string $id, string $status): void
{
    try {
        if ($kind === 'order') {
            $stmt = db()->prepare("SELECT status, status_history FROM orders WHERE number = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) return;
            if ($row['status'] === $status) return;
            $history = $row['status_history'] ? json_decode($row['status_history'], true) : [];
            $history[] = ['s' => $status, 't' => date('c')];
            $history = array_slice($history, -20);
            db_update('orders', ['status' => $status, 'status_history' => json_encode($history)], ['number' => $id]);
        } elseif ($kind === 'reservation') {
            db_update('reservations', ['status' => $status], ['id' => (int) $id]);
        } elseif ($kind === 'message') {
            db_update('contacts', ['status' => $status], ['id' => (int) $id]);
        }
    } catch (Throwable $e) {
        error_log("meta_set_status($kind, $id): " . $e->getMessage());
    }
}

/** Set fields on a record. */
function meta_set(string $kind, string $id, array $fields): void
{
    try {
        if ($kind === 'order') {
            $allowed = ['note', 'kds_started', 'kds_duration', 'kds_completed'];
            $data = [];
            foreach ($fields as $k => $v) {
                if (in_array($k, $allowed, true)) $data[$k] = $v;
            }
            if ($data) db_update('orders', $data, ['number' => $id]);
        } elseif ($kind === 'reservation') {
            $data = [];
            if (array_key_exists('note', $fields)) $data['note'] = (string) $fields['note'];
            if ($data) db_update('reservations', $data, ['id' => (int) $id]);
        } elseif ($kind === 'message') {
            $data = [];
            if (array_key_exists('status', $fields)) $data['status'] = (string) $fields['status'];
            if (array_key_exists('note', $fields)) $data['note'] = (string) $fields['note'];
            if ($data) db_update('contacts', $data, ['id' => (int) $id]);
        } elseif ($kind === 'chat') {
            $data = [];
            if (array_key_exists('flagged', $fields)) $data['flagged'] = $fields['flagged'] ? 1 : 0;
            if (array_key_exists('note', $fields)) $data['note'] = (string) $fields['note'];
            if ($data) db_update('chat_sessions', $data, ['cid' => $id]);
        }
    } catch (Throwable $e) {
        error_log("meta_set($kind, $id): " . $e->getMessage());
    }
}

function meta_forget(string $kind, string $id): void
{
}

// ---------- orders ----------

/** Normalize a DB order row to the shape the admin/KDS pages expect. */
function order_from_row(array $r): array
{
    $items = is_string($r['items'] ?? null) ? json_decode($r['items'], true) : ($r['items'] ?? []);
    $history = is_string($r['status_history'] ?? null) ? json_decode($r['status_history'], true) : ($r['status_history'] ?? []);
    return [
        'number'      => $r['number'],
        'time'        => $r['created_at'],
        'ts'          => (int) strtotime($r['created_at']),
        'payment'     => $r['payment'],
        'type'        => $r['order_type'],
        'table'       => $r['table_number'] !== null ? (int) $r['table_number'] : null,
        'name'        => $r['name'],
        'phone'       => $r['phone'],
        'email'       => $r['email'],
        'address'     => $r['address'],
        'notes'       => $r['order_notes'] ?? '',
        'items'       => array_values(array_map('strval', is_array($items) ? $items : [])),
        'subtotal'    => (int) $r['subtotal'],
        'deliveryFee' => (int) $r['delivery_fee'],
        'total'       => (int) $r['total'],
        'status'      => isset(ORDER_STATUSES[$r['status']]) ? $r['status'] : 'new',
        'note'        => (string) ($r['note'] ?? ''),
        'history'     => is_array($history) ? $history : [],
        'kds_started'   => $r['kds_started'] ?? null,
        'kds_duration'  => $r['kds_duration'] ?? null,
        'kds_completed' => $r['kds_completed'] ?? null,
    ];
}

/** Orders saved from $from to $to (Y-m-d, inclusive), newest first. */
function orders_between(string $from, string $to): array
{
    $stmt = db()->prepare("SELECT * FROM orders WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
    $stmt->execute([$from, $to]);
    return array_map('order_from_row', $stmt->fetchAll());
}

/** The last $days days of orders, today included. */
function orders_recent(int $days): array
{
    return orders_between(date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days')), date('Y-m-d'));
}

/** One order by its six-character number; null if not found. */
function order_find(string $number): ?array
{
    $number = strtoupper($number);
    if (!preg_match('/^[A-F0-9]{6}$/', $number)) return null;
    $stmt = db()->prepare("SELECT * FROM orders WHERE number = ?");
    $stmt->execute([$number]);
    $row = $stmt->fetch();
    return $row ? order_from_row($row) : null;
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

// ---------- reservations, contact messages, newsletter ----------

const RESERVATION_AREAS = ['main-bar' => 'Main Bar', 'monkey-bar' => 'Monkey Bar', 'patio' => 'Outdoor Patio'];

function reservation_area(string $area): string
{
    return RESERVATION_AREAS[$area] ?? ($area !== '' ? $area : 'No preference');
}

function reservation_party(string $guests): string
{
    return $guests === '8' ? '8+ guests' : ($guests === '1' ? '1 guest' : $guests . ' guests');
}

/** Reservation requests, newest first, with id, status, note, ts (received) and at (the booking time). */
function reservations_all(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    $stmt = db()->query("SELECT * FROM reservations ORDER BY received DESC");
    $out = [];
    while ($r = $stmt->fetch()) {
        $r['id'] = (string) $r['id'];
        $r['date'] = $r['reservation_date'];
        $r['time'] = $r['reservation_time'];
        $r['ts'] = (int) strtotime($r['received']);
        $r['at'] = (int) strtotime($r['reservation_date'] . ' ' . (preg_match('/^\d{1,2}:\d{2}$/', $r['reservation_time']) ? $r['reservation_time'] : '00:00'));
        $out[] = $r;
    }
    return $memo = $out;
}

/** Contact-form messages, newest first, with id, status, note and ts. */
function messages_all(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    $stmt = db()->query("SELECT * FROM contacts ORDER BY received DESC");
    $out = [];
    while ($r = $stmt->fetch()) {
        $r['id'] = (string) $r['id'];
        $r['ts'] = (int) strtotime($r['received']);
        $out[] = $r;
    }
    return $memo = $out;
}

/** Newsletter sign-ups, newest first. */
function subscribers_all(): array
{
    $stmt = db()->query("SELECT email, signed_up, page FROM subscribers ORDER BY signed_up DESC");
    $out = [];
    while ($r = $stmt->fetch()) {
        $out[] = [
            'email' => $r['email'],
            'ts'    => (int) strtotime($r['signed_up']),
            'page'  => $r['page'],
            'times' => 1,
        ];
    }
    return $out;
}

/** Delete a subscriber by email; returns true if removed. */
function subscriber_remove(string $email): bool
{
    $stmt = db()->prepare("DELETE FROM subscribers WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->rowCount() > 0;
}

/** Delete a contact message by id; returns true if removed. */
function message_delete(int $id): bool
{
    $stmt = db()->prepare("DELETE FROM contacts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// ---------- what needs attention ----------

/** Numbers for the sidebar badges: new orders, upcoming reservations waiting for an answer, unread messages. */
function admin_counts(): array
{
    $cutoff = date('Y-m-d', strtotime('-13 days'));
    $today = date('Y-m-d');

    $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE status = 'new' AND DATE(created_at) >= ?");
    $stmt->execute([$cutoff]);
    $orders = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND reservation_date >= ?");
    $stmt->execute([$today]);
    $reservations = (int) $stmt->fetchColumn();

    $messages = (int) db()->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn();

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

/** Note what someone did in the admin: $action is a short code (see the Activity page), $detail plain text. */
function admin_log(string $action, string $detail = ''): void
{
    try {
        db_insert('admin_activity', [
            'action' => $action,
            'detail' => mb_substr($detail, 0, 300),
            'ip'     => client_ip(),
        ]);
        if (mt_rand(1, 40) === 1) {
            $count = (int) db()->query("SELECT COUNT(*) FROM admin_activity")->fetchColumn();
            if ($count > 1500) {
                $keep = (int) db()->query("SELECT id FROM admin_activity ORDER BY id DESC LIMIT 1 OFFSET 1499")->fetchColumn();
                if ($keep) db()->exec("DELETE FROM admin_activity WHERE id < $keep");
            }
        }
    } catch (Throwable $e) {
        error_log('admin_log: ' . $e->getMessage());
    }
}

/** The newest $limit activity entries, newest first. */
function admin_activity(int $limit = 100): array
{
    $stmt = db()->prepare("SELECT logged_at AS time, action, detail, ip FROM admin_activity ORDER BY id DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}
