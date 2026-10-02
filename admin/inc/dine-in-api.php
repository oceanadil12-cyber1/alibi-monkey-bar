<?php
/**
 * Dine-in order creation (included from admin/index.php when api=dine-in).
 *
 * Expects POST with JSON body:
 *   { "table": 12, "items": [ { "name":"...", "qty":2, "price":899,
 *     "variation":"...", "mods":"...", "note":"..." }, ... ], "notes":"..." }
 */

$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');

$table = (int) ($req['table'] ?? 0);
if ($table < 1 || $table > 999) fail('Please select a table number.');

$items = $req['items'] ?? [];
if (!is_array($items) || count($items) === 0) fail('Please add at least one item.');
if (count($items) > 50) fail('Too many items.');

$orderNotes = trim(mb_substr((string) ($req['notes'] ?? ''), 0, 500));

$itemLines = [];
$subtotal = 0;
foreach ($items as $item) {
    $name = trim(mb_substr((string) ($item['name'] ?? ''), 0, 200));
    $qty = max(1, min(99, (int) ($item['qty'] ?? 1)));
    $price = (int) ($item['price'] ?? 0);
    $variation = trim(mb_substr((string) ($item['variation'] ?? ''), 0, 100));
    $mods = trim(mb_substr((string) ($item['mods'] ?? ''), 0, 300));
    $note = trim(mb_substr((string) ($item['note'] ?? ''), 0, 200));

    if ($name === '') continue;

    $label = $qty . ' x ' . $name
        . ($variation !== '' && $variation !== 'Regular' ? ' (' . $variation . ')' : '')
        . ($mods !== '' ? ' [' . $mods . ']' : '')
        . ($note !== '' ? ' - note: ' . $note : '');
    if ($price > 0) {
        $label .= ' = $' . number_format($price * $qty / 100, 2);
    }
    $itemLines[] = $label;
    $subtotal += $price * $qty;
}

if (count($itemLines) === 0) fail('No valid items in the order.');

$number = strtoupper(bin2hex(random_bytes(3)));

require_once __DIR__ . '/../../api/db.php';
save_row('orders', [
    'number'       => $number,
    'payment'      => 'dine-in',
    'order_type'   => 'dine-in',
    'table_number' => $table,
    'items'        => json_encode($itemLines, JSON_UNESCAPED_UNICODE),
    'order_notes'  => $orderNotes,
    'subtotal'     => $subtotal,
    'delivery_fee' => 0,
    'total'        => $subtotal,
    'ip'           => client_ip(),
]);

admin_log('order.dine-in', '#' . $number . ' table ' . $table . ' (' . count($itemLines) . ' items)');

notify("Dine-in order #$number (Table $table)", [
    'Order'    => "#$number",
    'Placed'   => date('l, M j, Y g:i A'),
    'Type'     => 'DINE-IN',
    'Table'    => $table,
    'Items'    => implode("\n", $itemLines),
    'Notes'    => $orderNotes ?: '(none)',
    'Subtotal' => '$' . number_format($subtotal / 100, 2),
]);

send_json([
    'ok'          => true,
    'orderNumber' => $number,
    'table'       => $table,
    'items'       => count($itemLines),
]);
