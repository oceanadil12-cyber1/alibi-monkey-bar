<?php
/**
 * GET api/chats.php — private page for reading the chat assistant's conversations.
 *
 * Protected by config 'chat_admin_password' (empty = page switched off).
 *   api/chats.php?days=30        conversations from the last 7 / 30 / 120 days
 *   api/chats.php?c=<id>         one conversation, as a chat
 *   api/chats.php?csv=1&days=30  download them as a spreadsheet (CSV)
 *
 * Guests' messages are shown escaped: they are untrusted text.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/chat-log.php';
require_once __DIR__ . '/menu.php';          // load_menu_base(), apply_menu_overrides()
require_once __DIR__ . '/menu-overrides.php'; // read/write local menu overrides

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Did the guest ask about a table reservation in this conversation? Returns the
 * first matching question (and party size if they gave one), else null. Detected
 * from the guest's own words, so treat it as a helpful flag, not a booking.
 */
function reservation_intent(array $c): ?array
{
    $re = '/\breserv\w*|book(?:ing)?\s+a\s+table|book\s+a\s+table|table\s+for\s+\d+|make\s+a\s+(?:booking|reservation)|hold\s+a\s+table|\btable\s+booking/i';
    foreach ($c['turns'] as $t) {
        $q = (string) ($t['question'] ?? '');
        if (preg_match($re, $q)) {
            $party = null;
            if (preg_match('/\b(?:party of|group of|table for|for)\s+(\d{1,3})\b|\b(\d{1,3})\s*(?:people|guests|persons|pax|ppl|of us|adults)\b/i', $q, $m)) {
                $party = (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0)) ?: null;
            }
            return ['quote' => $q, 'party' => $party, 'time' => $t['time'] ?? ''];
        }
    }
    return null;
}

// ---------- Food tab ----------

/** "15.99" or "$15.99" → 1599 cents; '' or non-numeric → null. */
function dollars_to_cents(string $s): ?int
{
    $s = trim(str_replace(['$', ','], '', $s));
    if ($s === '' || !is_numeric($s)) return null;
    return (int) round(((float) $s) * 100);
}

/** Save the submitted Food form to the overrides file (only non-default values). */
function save_food_overrides(array $base): void
{
    $basePrice = [];
    foreach ($base['items'] as $id => $it) {
        foreach ($it['variations'] as $v) $basePrice[$id][$v['id']] = (int) $v['price'];
    }
    $existing = read_menu_overrides();
    $existingItems = $existing['items'] ?? [];
    $out = ['items' => [], 'custom' => $existing['custom'] ?? []]; // keep custom items
    foreach (array_map('strval', (array) ($_POST['ids'] ?? [])) as $id) {
        if (!isset($base['items'][$id])) continue;
        $o = [];
        // Preserve per-item option/description edits made on the item editor page.
        foreach (['description', 'hideGroups', 'hideChoices', 'choicePrices'] as $k) {
            if (isset($existingItems[$id][$k])) $o[$k] = $existingItems[$id][$k];
        }
        if (!isset($_POST['avail'][$id])) $o['hidden'] = true;
        if (!empty($_POST['pickup'][$id])) $o['pickupOnly'] = true;
        $dfee = dollars_to_cents((string) ($_POST['dfee'][$id] ?? ''));
        if ($dfee) $o['deliveryFee'] = $dfee;
        $prep = (int) ($_POST['prep'][$id] ?? 0);
        if ($prep > 0) $o['prepMinutes'] = min(600, $prep);
        foreach ((array) ($_POST['price'][$id] ?? []) as $vid => $dollars) {
            $c = dollars_to_cents((string) $dollars);
            $b = $basePrice[$id][(string) $vid] ?? null;
            if ($c !== null && $b !== null && $c !== $b) $o['prices'][(string) $vid] = $c;
        }
        if ($o) $out['items'][$id] = $o;
    }
    write_menu_overrides($out);
}

/** Category id → name, from the base (Square) menu. */
function food_categories(array $base): array
{
    $cats = [];
    foreach ($base['categories'] as $c) $cats[$c['id']] = $c['name'];
    return $cats;
}

/** Add a brand-new custom item (not in Square) from the "Add item" form. */
function add_custom_item(array $base): void
{
    $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80));
    $price = dollars_to_cents((string) ($_POST['price'] ?? ''));
    if ($name === '' || $price === null) return; // need at least a name and price
    $cats = food_categories($base);
    $catId = (string) ($_POST['category'] ?? '');
    $ov = read_menu_overrides();
    $ov['custom'] = $ov['custom'] ?? [];
    $ov['custom'][] = [
        'id'           => 'local-' . bin2hex(random_bytes(4)),
        'name'         => $name,
        'description'  => trim(mb_substr((string) ($_POST['description'] ?? ''), 0, 300)),
        'price'        => max(0, $price),
        'category'     => $catId,
        'categoryName' => $cats[$catId] ?? 'More',
        'pickupOnly'   => !empty($_POST['pickup']),
        'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['dfee'] ?? '')) ?? 0)),
        'prepMinutes'  => min(600, max(0, (int) ($_POST['prep'] ?? 0))),
    ];
    write_menu_overrides($ov);
}

/** Save edits and deletions to existing custom items. */
function save_custom_items(array $base): void
{
    $cats = food_categories($base);
    $ov = read_menu_overrides();
    $prev = [];
    foreach ($ov['custom'] ?? [] as $c) if (!empty($c['id'])) $prev[(string) $c['id']] = $c;
    $out = [];
    foreach (array_map('strval', (array) ($_POST['cid'] ?? [])) as $id) {
        if (!isset($prev[$id]) || !empty($_POST['cdelete'][$id])) continue; // deleted
        $name = trim(mb_substr((string) ($_POST['cname'][$id] ?? ''), 0, 80));
        if ($name === '') continue;
        $catId = (string) ($_POST['ccat'][$id] ?? '');
        $out[] = [
            'id'           => $id,
            'name'         => $name,
            'description'  => trim(mb_substr((string) ($_POST['cdesc'][$id] ?? ''), 0, 300)),
            'price'        => max(0, (int) (dollars_to_cents((string) ($_POST['cprice'][$id] ?? '')) ?? 0)),
            'category'     => $catId,
            'categoryName' => $cats[$catId] ?? 'More',
            'pickupOnly'   => !empty($_POST['cpickup'][$id]),
            'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['cdfee'][$id] ?? '')) ?? 0)),
            'prepMinutes'  => min(600, max(0, (int) ($_POST['cprep'][$id] ?? 0))),
        ];
    }
    $ov['custom'] = $out;
    write_menu_overrides($ov);
}

// ---------- Store settings tab ----------

/** "11:00" → "11:00:00"; invalid → null. */
function hms(string $t): ?string
{
    $t = trim($t);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }
    return null;
}

/** Save the Store settings form to store-settings.json. */
function save_store_settings(): void
{
    $days = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    $readHours = function (string $prefix) use ($days) {
        $out = [];
        foreach ($days as $d) {
            $o = hms((string) ($_POST[$prefix . '_open'][$d] ?? ''));
            $c = hms((string) ($_POST[$prefix . '_close'][$d] ?? ''));
            if ($o !== null && $c !== null) $out[$d] = [['open' => $o, 'close' => $c]];
        }
        return $out;
    };
    $zips = [];
    foreach (explode(',', (string) ($_POST['deliveryZips'] ?? '')) as $z) {
        $z = substr(preg_replace('/\D/', '', $z), 0, 5);
        if ($z !== '') $zips[] = $z;
    }
    write_store_settings([
        'name'               => trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80)),
        'address'            => trim(mb_substr((string) ($_POST['address'] ?? ''), 0, 160)),
        'phone'              => trim(mb_substr((string) ($_POST['phone'] ?? ''), 0, 40)),
        'prepMinutes'        => min(600, max(0, (int) ($_POST['prep'] ?? 25))),
        'pickupMinimum'      => max(0, (int) (dollars_to_cents((string) ($_POST['pickupMin'] ?? '')) ?? 0)),
        'deliveryEnabled'    => !empty($_POST['deliveryEnabled']),
        'deliveryFee'        => max(0, (int) (dollars_to_cents((string) ($_POST['deliveryFee'] ?? '')) ?? 0)),
        'deliveryMinimum'    => max(0, (int) (dollars_to_cents((string) ($_POST['deliveryMin'] ?? '')) ?? 0)),
        'deliveryZips'       => array_values(array_unique($zips)),
        'pickupInstructions' => trim(mb_substr((string) ($_POST['pickupInstructions'] ?? ''), 0, 300)),
        'pickupHours'        => $readHours('pickup'),
        'deliveryHours'      => $readHours('delivery'),
    ]);
}

/** The Store settings tab body. */
function settings_html(array $store, string $csrf, bool $saved): string
{
    $days = ['MON' => 'Monday', 'TUE' => 'Tuesday', 'WED' => 'Wednesday', 'THU' => 'Thursday', 'FRI' => 'Friday', 'SAT' => 'Saturday', 'SUN' => 'Sunday'];
    $money = fn($c) => number_format(((int) $c) / 100, 2, '.', '');
    $timeVal = fn(array $hours, string $d, string $which) => isset($hours[$d][0][$which]) ? substr((string) $hours[$d][0][$which], 0, 5) : '';
    $hoursTable = function (string $prefix, array $hours) use ($days, $timeVal) {
        $rows = '';
        foreach ($days as $k => $name) {
            $rows .= '<tr><td>' . $name . '</td>'
                . '<td><input type="time" name="' . $prefix . '_open[' . $k . ']" value="' . h($timeVal($hours, $k, 'open')) . '"></td>'
                . '<td><input type="time" name="' . $prefix . '_close[' . $k . ']" value="' . h($timeVal($hours, $k, 'close')) . '"></td></tr>';
        }
        return '<table class="shours"><thead><tr><th>Day</th><th>Open</th><th>Close</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    };

    return '<h1>Store settings</h1>'
        . '<p class="muted">Hours, delivery area and fees for the website &mdash; these used to come from Square, now they\'re yours to edit. Leave a day\'s times blank to mark it closed.</p>'
        . ($saved ? '<div class="fsaved">&#10003; Saved. The website updates within a couple of minutes.</div>' : '')
        . '<form method="post" action="?view=settings"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<section class="fadd"><h2>Business</h2><div class="fadd-grid">'
        . '<label class="fa"><span>Name</span><input name="name" value="' . h($store['name'] ?? '') . '" maxlength="80"></label>'
        . '<label class="fa"><span>Address</span><input name="address" value="' . h($store['address'] ?? '') . '" maxlength="160"></label>'
        . '<label class="fa fa-sm"><span>Phone</span><input name="phone" value="' . h($store['phone'] ?? '') . '" maxlength="40"></label>'
        . '</div></section>'
        . '<section class="fadd"><h2>Ordering &amp; delivery</h2><div class="fadd-grid">'
        . '<label class="fa fa-sm"><span>Prep time</span><span class="fprep"><input name="prep" inputmode="numeric" value="' . (int) ($store['prepMinutes'] ?? 25) . '"> min</span></label>'
        . '<label class="fa fa-sm"><span>Pickup minimum</span><span class="fp-in">$<input name="pickupMin" inputmode="decimal" value="' . $money($store['pickupMinimum'] ?? 0) . '"></span></label>'
        . '<label class="fchk fa-chk"><input type="checkbox" name="deliveryEnabled" value="1" ' . (!empty($store['deliveryEnabled']) ? 'checked' : '') . '> Delivery enabled</label>'
        . '<label class="fa fa-sm"><span>Delivery fee</span><span class="fp-in">$<input name="deliveryFee" inputmode="decimal" value="' . $money($store['deliveryFee'] ?? 0) . '"></span></label>'
        . '<label class="fa fa-sm"><span>Delivery minimum</span><span class="fp-in">$<input name="deliveryMin" inputmode="decimal" value="' . $money($store['deliveryMinimum'] ?? 0) . '"></span></label>'
        . '<label class="fa"><span>Delivery ZIP codes (comma separated)</span><input name="deliveryZips" value="' . h(implode(', ', $store['deliveryZips'] ?? [])) . '"></label>'
        . '<label class="fa fa-wide"><span>Pickup instructions (optional)</span><input name="pickupInstructions" value="' . h($store['pickupInstructions'] ?? '') . '" maxlength="300"></label>'
        . '</div></section>'
        . '<section class="fadd"><h2>Hours</h2><div class="shours-wrap">'
        . '<div><h3>Pickup</h3>' . $hoursTable('pickup', $store['pickupHours'] ?? []) . '</div>'
        . '<div><h3>Delivery</h3>' . $hoursTable('delivery', $store['deliveryHours'] ?? []) . '</div>'
        . '</div></section>'
        . '<div class="fbar"><button type="submit" class="fsave">Save settings</button></div></form>';
}

// ---------- per-item options editor ----------

/** Save description + option-group edits for one item (keeps its price/pickup/etc.). */
function save_item_options(array $base): void
{
    $id = (string) ($_POST['item'] ?? '');
    if (!isset($base['items'][$id])) return;
    $baseItem = $base['items'][$id];
    $basePrice = [];
    foreach ($baseItem['modifiers'] as $g) foreach ($g['choices'] as $c) $basePrice[(string) $c['id']] = (int) $c['price'];

    $ov = read_menu_overrides();
    $ov['items'] = $ov['items'] ?? [];
    $o = $ov['items'][$id] ?? []; // keep existing basic fields (price/pickup/fee/prep)

    $desc = trim(mb_substr((string) ($_POST['description'] ?? ''), 0, 300));
    if ($desc !== '' && $desc !== (string) ($baseItem['description'] ?? '')) $o['description'] = $desc;
    else unset($o['description']);

    $hideG = array_values(array_map('strval', (array) ($_POST['hidegroup'] ?? [])));
    $hideC = array_values(array_map('strval', (array) ($_POST['hidechoice'] ?? [])));
    if ($hideG) $o['hideGroups'] = $hideG; else unset($o['hideGroups']);
    if ($hideC) $o['hideChoices'] = $hideC; else unset($o['hideChoices']);

    $cp = [];
    foreach ((array) ($_POST['chprice'] ?? []) as $cid => $val) {
        $c = dollars_to_cents((string) $val);
        if ($c !== null && isset($basePrice[(string) $cid]) && $c !== $basePrice[(string) $cid]) $cp[(string) $cid] = $c;
    }
    if ($cp) $o['choicePrices'] = $cp; else unset($o['choicePrices']);

    if ($o) $ov['items'][$id] = $o; else unset($ov['items'][$id]);
    write_menu_overrides($ov);
}

/** The per-item editor: description + each option group's choices (re-price / hide). */
function item_editor_html(array $item, array $o, string $csrf, bool $saved): string
{
    $id = (string) $item['id'];
    $hideG = array_flip($o['hideGroups'] ?? []);
    $hideC = array_flip($o['hideChoices'] ?? []);
    $cp = $o['choicePrices'] ?? [];
    $desc = $o['description'] ?? ($item['description'] ?? '');

    $groups = '';
    foreach ($item['modifiers'] as $g) {
        $choices = '';
        foreach ($g['choices'] as $c) {
            $price = $cp[$c['id']] ?? $c['price'];
            $choices .= '<tr>'
                . '<td>' . h($c['name']) . '</td>'
                . '<td><span class="fp-in">$<input name="chprice[' . h($c['id']) . ']" inputmode="decimal" value="' . number_format((int) $price / 100, 2, '.', '') . '"></span> <small class="muted">Square $' . number_format((int) $c['price'] / 100, 2) . '</small></td>'
                . '<td><label class="fchk"><input type="checkbox" name="hidechoice[]" value="' . h($c['id']) . '" ' . (isset($hideC[$c['id']]) ? 'checked' : '') . '> Hide</label></td></tr>';
        }
        $groups .= '<section class="fgrp"><div class="fgrp-head"><strong>' . h($g['name']) . '</strong>'
            . '<label class="fchk fdel"><input type="checkbox" name="hidegroup[]" value="' . h($g['id']) . '" ' . (isset($hideG[$g['id']]) ? 'checked' : '') . '> Hide whole group</label></div>'
            . '<table class="ftable"><tbody>' . $choices . '</tbody></table></section>';
    }
    if ($groups === '') $groups = '<p class="muted">This item has no options to edit.</p>';

    return '<p><a href="?view=food">&larr; Food &amp; menu</a></p>'
        . '<h1>' . h($item['name']) . '</h1>'
        . ($saved ? '<div class="fsaved">&#10003; Saved. The website updates within a couple of minutes.</div>' : '')
        . '<form method="post" action="?view=food&amp;item=' . rawurlencode($id) . '"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="op" value="item"><input type="hidden" name="item" value="' . h($id) . '">'
        . '<label class="fa fa-wide"><span>Description</span><textarea name="description" maxlength="300" rows="2">' . h($desc) . '</textarea></label>'
        . '<h2 style="margin-top:22px">Options</h2><p class="muted">Re-price or hide individual choices, or hide a whole group.</p>'
        . $groups
        . '<div class="fbar"><button type="submit" class="fsave">Save item</button></div></form>';
}

/** The Food tab body: a form to hide / re-price / restrict / surcharge each item. */
function food_html(array $base, array $over, string $csrf, bool $saved): string
{
    $ov = $over['items'];
    $money = fn($c) => '$' . number_format($c / 100, 2);
    $storePrep = (int) ($base['store']['prepMinutes'] ?? 25);
    $rows = '';
    $count = 0;
    foreach ($base['categories'] as $cat) {
        $items = '';
        foreach ($cat['itemIds'] as $id) {
            $it = $base['items'][$id] ?? null;
            if (!$it) continue;
            $count++;
            $o = $ov[$id] ?? [];
            $hidden = !empty($o['hidden']);
            $priceInputs = '';
            foreach ($it['variations'] as $v) {
                $cur = $o['prices'][$v['id']] ?? $v['price'];
                $label = $v['name'] !== 'Regular' ? h($v['name']) : 'Price';
                $priceInputs .= '<label class="fp"><span class="fp-lbl">' . $label . '</span>'
                    . '<span class="fp-in">$<input inputmode="decimal" name="price[' . h($id) . '][' . h($v['id']) . ']" value="' . number_format($cur / 100, 2, '.', '') . '"></span>'
                    . '<small>Square ' . $money($v['price']) . '</small></label>';
            }
            $items .= '<tr class="' . ($hidden ? 'is-off' : '') . '">'
                . '<td><label class="fsw"><input type="checkbox" name="avail[' . h($id) . ']" value="1" ' . ($hidden ? '' : 'checked') . '><span></span></label></td>'
                . '<td class="fname"><a href="?view=food&amp;item=' . rawurlencode($id) . '" title="Edit options &amp; description">' . h($it['name']) . '</a>'
                . (!empty($it['soldOut']) ? ' <span class="fbadge">sold out on Square</span>' : '')
                . (($it['modifiers'] ?? []) ? ' <span class="fopts">' . count($it['modifiers']) . ' option' . (count($it['modifiers']) === 1 ? '' : 's') . '</span>' : '') . '</td>'
                . '<td class="fprices">' . $priceInputs . '</td>'
                . '<td><label class="fchk"><input type="checkbox" name="pickup[' . h($id) . ']" value="1" ' . (!empty($o['pickupOnly']) ? 'checked' : '') . '> Pickup only</label></td>'
                . '<td><span class="fp-in">$<input inputmode="decimal" name="dfee[' . h($id) . ']" value="' . (!empty($o['deliveryFee']) ? number_format($o['deliveryFee'] / 100, 2, '.', '') : '') . '" placeholder="0.00"></span></td>'
                . '<td class="fprep"><input inputmode="numeric" name="prep[' . h($id) . ']" value="' . (!empty($o['prepMinutes']) ? (int) $o['prepMinutes'] : '') . '" placeholder="' . $storePrep . '"> min</td>'
                . '<input type="hidden" name="ids[]" value="' . h($id) . '"></tr>';
        }
        if ($items) $rows .= '<tr class="fcat"><td colspan="6">' . h($cat['name']) . '</td></tr>' . $items;
    }

    // Category dropdown options.
    $cats = [];
    foreach ($base['categories'] as $cat) $cats[$cat['id']] = $cat['name'];
    $catOptions = fn($sel) => implode('', array_map(fn($id) => '<option value="' . h($id) . '"' . ($id === $sel ? ' selected' : '') . '>' . h($cats[$id]) . '</option>', array_keys($cats)));

    // "Add a new item" form (op=add).
    $addForm = '<section class="fadd"><h2>Add a new item</h2>'
        . '<p class="muted">Items you add here don\'t have to exist in Square &mdash; they\'re orderable on the website (cash on delivery).</p>'
        . '<form method="post" action="?view=food"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="op" value="add">'
        . '<div class="fadd-grid">'
        . '<label class="fa"><span>Name</span><input name="name" maxlength="80" required></label>'
        . '<label class="fa fa-sm"><span>Price</span><span class="fp-in">$<input name="price" inputmode="decimal" required></span></label>'
        . '<label class="fa fa-sm"><span>Category</span><select name="category">' . $catOptions('') . '</select></label>'
        . '<label class="fa fa-sm"><span>Delivery surcharge</span><span class="fp-in">$<input name="dfee" inputmode="decimal" placeholder="0.00"></span></label>'
        . '<label class="fa fa-sm"><span>Prep time</span><span class="fprep"><input name="prep" inputmode="numeric" placeholder="' . $storePrep . '"> min</span></label>'
        . '<label class="fa fa-wide"><span>Description (optional)</span><input name="description" maxlength="300"></label>'
        . '<label class="fchk fa-chk"><input type="checkbox" name="pickup" value="1"> Pickup only</label>'
        . '</div><div class="fbar"><button type="submit" class="fsave">Add item</button></div></form></section>';

    // Existing custom items editor (op=custom).
    $customRows = '';
    foreach ($over['custom'] ?? [] as $c) {
        $id = (string) ($c['id'] ?? '');
        if ($id === '') continue;
        $customRows .= '<tr>'
            . '<td><input name="cname[' . h($id) . ']" value="' . h($c['name'] ?? '') . '" maxlength="80"></td>'
            . '<td><span class="fp-in">$<input name="cprice[' . h($id) . ']" inputmode="decimal" value="' . number_format((int) ($c['price'] ?? 0) / 100, 2, '.', '') . '"></span></td>'
            . '<td><select name="ccat[' . h($id) . ']">' . $catOptions((string) ($c['category'] ?? '')) . '</select></td>'
            . '<td><label class="fchk"><input type="checkbox" name="cpickup[' . h($id) . ']" value="1" ' . (!empty($c['pickupOnly']) ? 'checked' : '') . '> Pickup only</label></td>'
            . '<td><span class="fp-in">$<input name="cdfee[' . h($id) . ']" inputmode="decimal" value="' . (!empty($c['deliveryFee']) ? number_format($c['deliveryFee'] / 100, 2, '.', '') : '') . '" placeholder="0.00"></span></td>'
            . '<td class="fprep"><input name="cprep[' . h($id) . ']" inputmode="numeric" value="' . (!empty($c['prepMinutes']) ? (int) $c['prepMinutes'] : '') . '" placeholder="' . $storePrep . '"> min</td>'
            . '<td><label class="fchk fdel"><input type="checkbox" name="cdelete[' . h($id) . ']" value="1"> Delete</label></td>'
            . '<input type="hidden" name="cid[]" value="' . h($id) . '"></tr>';
    }
    $customSection = $customRows
        ? '<section class="fcustom"><h2>Your added items</h2>'
            . '<form method="post" action="?view=food"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="op" value="custom">'
            . '<div class="table"><table class="ftable"><thead><tr><th>Name</th><th>Price</th><th>Category</th><th>Fulfillment</th><th>Delivery surcharge</th><th>Prep time</th><th></th></tr></thead><tbody>'
            . $customRows
            . '</tbody></table></div><div class="fbar"><button type="submit" class="fsave">Save added items</button></div></form></section>'
        : '';

    return '<h1>Food &amp; menu</h1>'
        . '<p class="muted">Live from your Square store; changes here apply on top of it, for the website only. Orders are cash on delivery, so the price you set is what the guest pays. Turn an item off to hide it; blank price keeps the Square price; blank prep uses the store default (' . $storePrep . ' min).</p>'
        . ($saved ? '<div class="fsaved">&#10003; Saved. The website updates within a couple of minutes.</div>' : '')
        . $addForm
        . $customSection
        . '<section class="fgridsec"><h2>Square menu</h2>'
        . '<form class="ffood" method="post" action="?view=food">'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="op" value="items">'
        . '<div class="fbar"><span class="muted">' . $count . ' items</span><button type="submit" class="fsave">Save changes</button></div>'
        . '<div class="table"><table class="ftable"><thead><tr><th>On</th><th>Item</th><th>Price</th><th>Fulfillment</th><th>Delivery surcharge</th><th>Prep time</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table></div>'
        . '<div class="fbar"><button type="submit" class="fsave">Save changes</button></div></form></section>';
}

function page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    $logout = !empty($_SESSION['chats_ok'])
        ? '<a class="logout" href="?logout=1"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Log out</a>'
        : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' | Alibi chat history</title><style>' . PAGE_CSS . '</style></head><body>'
        . '<header class="top"><div class="brand">'
        . '<img src="../images/logo.webp" alt="Georgie\'s Alibi Monkey Bar" height="44">'
        . '<span class="divider"></span><span class="wm"><b>Chat History</b><small>Team dashboard</small></span></div>'
        . '<span class="spacer"></span>' . $logout . '</header>'
        . '<main>' . $body . '</main></body></html>';
    exit;
}

const PAGE_CSS = <<<'CSS'
:root {
  --bg: #0b0b0c; --bg-2: #121214; --card: #161618; --card-2: #1c1c1f;
  --line: #29292d; --line-soft: #202024; --text: #ececed; --muted: #8d8d94; --muted-2: #6a6a70;
  --gold: #c8a84e; --gold-light: #e6cd7d; --gold-dim: rgba(200,168,78,.14); --radius: 16px;
}
* { box-sizing: border-box; }
html, body { margin: 0; }
body {
  background: radial-gradient(1200px 500px at 85% -10%, rgba(200,168,78,.10), transparent 60%), radial-gradient(900px 500px at 0% 0%, rgba(200,168,78,.05), transparent 55%), var(--bg);
  color: var(--text); font: 15px/1.5 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; min-height: 100vh;
}
a { color: var(--gold-light); }
header.top { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 16px; padding: 13px 24px; background: rgba(12,12,13,.85); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line-soft); }
header.top::after { content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 1px; background: linear-gradient(90deg, transparent, rgba(200,168,78,.5), transparent); }
.brand { display: flex; align-items: center; gap: 13px; min-width: 0; }
.brand img { height: 44px; width: auto; display: block; }
.brand .divider { width: 1px; height: 28px; background: var(--line); }
.brand .wm { display: flex; flex-direction: column; line-height: 1.15; }
.brand .wm b { font-size: 15px; font-weight: 700; }
.brand .wm small { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1.4px; }
header.top .spacer { flex: 1; }
.logout { display: inline-flex; align-items: center; gap: 7px; padding: 8px 15px; border-radius: 999px; border: 1px solid var(--line); background: var(--card); color: var(--muted); font-size: 13px; font-weight: 500; text-decoration: none; }
.logout:hover { color: var(--text); border-color: #3a3a40; }
main { max-width: 1060px; margin: 0 auto; padding: 26px 18px 70px; }
h1 { font-size: 26px; font-weight: 800; letter-spacing: -.4px; margin: 0 0 4px; }
.muted { color: var(--muted); font-size: 13px; }
.views { display: flex; gap: 6px; margin-bottom: 22px; }
.views a { padding: 9px 17px; border-radius: 999px; color: var(--muted); text-decoration: none; font-size: 14px; font-weight: 600; }
.views a:hover { color: var(--text); }
.views a.on { color: #14140f; background: linear-gradient(180deg, var(--gold-light), var(--gold)); }
.bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 18px 0 14px; }
.bar a { padding: 7px 15px; border-radius: 999px; text-decoration: none; color: var(--muted); background: var(--card); border: 1px solid var(--line); font-size: 13px; font-weight: 600; }
.bar a:hover { color: var(--text); }
.bar a.on { color: var(--gold-light); background: var(--gold-dim); border-color: rgba(200,168,78,.4); }
.bar .dl { margin-left: auto; color: var(--gold-light); background: var(--gold-dim); border-color: rgba(200,168,78,.35); }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(158px, 1fr)); gap: 13px; margin-bottom: 24px; }
.stat { position: relative; overflow: hidden; background: linear-gradient(180deg, var(--card-2), var(--card)); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px 16px 15px; color: var(--muted); font-size: 13px; }
.stat::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 2px; background: linear-gradient(90deg, var(--gold), transparent); opacity: .5; }
.stat .ico { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; background: var(--gold-dim); color: var(--gold-light); margin-bottom: 11px; }
.stat .ico svg { width: 17px; height: 17px; }
.stat b { display: block; font-size: 28px; font-weight: 800; letter-spacing: -.5px; line-height: 1; color: var(--gold-light); margin-bottom: 5px; }
.table { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius); background: var(--card); }
table { width: 100%; border-collapse: collapse; min-width: 800px; }
thead th { text-align: left; padding: 13px 16px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--muted-2); background: var(--bg-2); border-bottom: 1px solid var(--line); }
tbody td { padding: 13px 16px; border-bottom: 1px solid var(--line-soft); vertical-align: middle; font-size: 13.5px; }
tbody tr { transition: background .12s ease; }
tbody tr:hover { background: #191a1d; }
tbody tr:last-child td { border-bottom: none; }
td.q { max-width: 320px; color: #cfcfd2; }
td.nowrap { white-space: nowrap; color: var(--muted); }
.gcell { display: flex; align-items: center; gap: 11px; }
.avatar { flex: none; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 14px; color: #14140f; background: linear-gradient(180deg, var(--gold-light), var(--gold)); }
.avatar.empty { background: #232327; color: var(--muted-2); }
.ginfo { min-width: 0; }
.ginfo b { display: block; font-size: 14px; font-weight: 600; }
.ginfo a { display: inline-block; font-size: 12.5px; color: var(--muted); text-decoration: none; word-break: break-all; }
.ginfo a:hover { color: var(--gold-light); }
.ret { display: inline-block; margin-top: 3px; font-size: 11px; color: var(--gold-light); }
.rez { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 999px; background: rgba(120,170,255,.14); color: #9cc0ff; font-size: 11px; font-weight: 600; }
.who { margin: 0 0 8px; font-size: 15px; }
.who a { word-break: break-all; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }
.chip { display: inline-block; padding: 4px 11px; border-radius: 999px; background: var(--card-2); border: 1px solid var(--line); color: var(--muted); font-size: 12.5px; }
.chip.gold { background: #2a2210; border-color: rgba(200,168,78,.4); color: var(--gold-light); }
.prev, .resv { margin: 14px 0 4px; padding: 13px 15px; border-radius: 13px; }
.prev { background: #17140b; border: 1px solid rgba(200,168,78,.35); }
.prev strong { color: var(--gold-light); font-size: 13.5px; }
.prev ul { margin: 8px 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.prev li { font-size: 13px; }
.prev li a { font-weight: 600; white-space: nowrap; }
.resv { background: #0e1830; border: 1px solid rgba(120,170,255,.4); }
.resv strong { color: #9cc0ff; font-size: 13.5px; }
.resv p { margin: 6px 0 0; font-size: 13.5px; color: var(--text); }
form.dates { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 20px; font-size: 13px; color: var(--muted); }
form.dates label { display: inline-flex; align-items: center; gap: 6px; }
form.dates input[type=date] { padding: 7px 10px; border-radius: 9px; border: 1px solid var(--line); background: #0f0f10; color: var(--text); font-size: 13px; color-scheme: dark; }
form.dates button { padding: 8px 15px; border: none; border-radius: 999px; background: var(--gold); color: #111; font-weight: 700; font-size: 13px; cursor: pointer; }
form.dates .clear { color: var(--muted); margin-left: 2px; }
.tag { display: inline-block; padding: 3px 9px; border-radius: 999px; background: var(--gold-dim); color: var(--gold-light); font-size: 12px; font-weight: 600; }
.chat { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
.chat .msg { max-width: 78%; padding: 10px 14px; border-radius: 16px; white-space: pre-wrap; overflow-wrap: anywhere; }
.chat .guest { align-self: flex-end; background: var(--gold); color: #111; border-bottom-right-radius: 5px; }
.chat .bot { align-self: flex-start; background: var(--card); border: 1px solid var(--line); border-bottom-left-radius: 5px; }
.chat .meta { font-size: 11.5px; color: var(--muted); margin-top: -4px; }
.chat .meta.r { align-self: flex-end; }
.chat .order { align-self: flex-start; border: 1px solid rgba(200, 168, 78, .5); border-radius: 12px; padding: 8px 12px; background: #1b170c; font-size: 13.5px; }
form.login { max-width: 360px; margin: 8vh auto; background: linear-gradient(180deg, var(--card-2), var(--card)); border: 1px solid var(--line); border-radius: 16px; padding: 26px; }
form.login h1 { font-size: 22px; }
form.login input { width: 100%; margin: 12px 0 14px; padding: 11px 13px; border-radius: 10px; border: 1px solid var(--line); background: #0f0f10; color: var(--text); font-size: 16px; color-scheme: dark; }
form.login button { width: 100%; padding: 12px; border: none; border-radius: 10px; background: linear-gradient(180deg, var(--gold-light), var(--gold)); color: #111; font-weight: 700; font-size: 15px; cursor: pointer; }
.err { color: #f3a4a4; font-size: 13.5px; }
.empty { padding: 36px; text-align: center; color: var(--muted); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; }
.panel { background: linear-gradient(180deg, var(--card-2), var(--card)); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px 16px 18px; min-width: 0; }
.panel.wide { grid-column: 1 / -1; }
.panel h2 { margin: 0 0 2px; font-size: 16px; }
.panel h3 { margin: 18px 0 8px; font-size: 13px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.panel .sub { margin: 0 0 14px; color: var(--muted); font-size: 12.5px; }
.bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
.bars li { display: grid; grid-template-columns: minmax(96px, 36%) 1fr 34px; gap: 10px; align-items: center; font-size: 13.5px; cursor: default; }
.bars .lbl { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bars .track { height: 10px; }
.bars .fill { display: block; height: 10px; background: var(--gold); border-radius: 0 4px 4px 0; }
.bars li:hover .fill { background: var(--gold-light); }
.bars li:hover .lbl { color: #fff; }
.bars .val { text-align: right; color: var(--text); font-variant-numeric: tabular-nums; }
.gap { height: 16px; }
.minis { display: flex; flex-wrap: wrap; gap: 10px; }
.minis div { flex: 1 1 120px; background: #111; border: 1px solid var(--line); border-radius: 10px; padding: 8px 12px; color: var(--muted); font-size: 12.5px; }
.minis b { display: block; color: var(--text); font-size: 18px; }
.quotes { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.quotes li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 10px; background: #111; border-radius: 8px; font-size: 13.5px; }
.quotes a { color: var(--text); text-decoration: none; }
.quotes a:hover { color: var(--gold-light); }
.quotes small { flex: none; color: var(--muted); }
.empty-sm { color: var(--muted); font-size: 13px; margin: 4px 0; }
code { background: #111; padding: 1px 5px; border-radius: 4px; font-size: 12px; }
/* Food tab */
.fbar { display: flex; align-items: center; gap: 12px; margin: 14px 0; }
.fsave { margin-left: auto; padding: 10px 20px; border: none; border-radius: 999px; background: linear-gradient(180deg, var(--gold-light), var(--gold)); color: #111; font-weight: 700; font-size: 14px; cursor: pointer; }
.fsaved { margin: 14px 0; padding: 11px 15px; border-radius: 12px; background: rgba(120,200,120,.12); border: 1px solid rgba(120,200,120,.4); color: #a6e0a6; font-size: 13.5px; }
.ftable { min-width: 880px; }
.ftable td { vertical-align: middle; }
tr.fcat td { background: var(--bg-2); color: var(--gold-light); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; font-size: 11.5px; padding: 9px 16px; }
tr.is-off { opacity: .5; }
.fname strong { font-size: 13.5px; }
.fbadge { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: #3a1414; color: #f0a0a0; font-size: 11px; }
.fprices { display: flex; flex-wrap: wrap; gap: 10px; }
.fp { display: flex; flex-direction: column; gap: 2px; font-size: 11px; color: var(--muted); }
.fp-lbl { font-weight: 600; }
.fp-in { display: inline-flex; align-items: center; gap: 3px; color: var(--muted); font-size: 13px; }
.fp-in input, .fprep input { width: 74px; padding: 7px 8px; border: 1px solid var(--line); border-radius: 8px; background: #0f0f10; color: var(--text); font-size: 13px; color-scheme: dark; }
.fprep input { width: 56px; }
.fp small { color: var(--muted-2); font-size: 10.5px; }
.fchk { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--muted); white-space: nowrap; }
/* on/off switch */
.fsw { position: relative; display: inline-block; width: 40px; height: 22px; cursor: pointer; }
.fsw input { position: absolute; opacity: 0; }
.fsw span { position: absolute; inset: 0; background: #33333a; border-radius: 999px; transition: background .15s ease; }
.fsw span::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #cfcfcf; transition: transform .15s ease; }
.fsw input:checked + span { background: var(--gold); }
.fsw input:checked + span::after { transform: translateX(18px); background: #111; }
.fadd, .fcustom, .fgridsec { margin-top: 30px; }
.fadd h2, .fcustom h2, .fgridsec h2 { font-size: 17px; margin: 0 0 4px; }
.fadd { padding: 18px; border: 1px solid var(--line); border-radius: var(--radius); background: linear-gradient(180deg, var(--card-2), var(--card)); }
.fadd-grid { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; margin-top: 12px; }
.fa { display: flex; flex-direction: column; gap: 5px; flex: 1 1 200px; }
.fa.fa-sm { flex: 0 0 140px; }
.fa.fa-wide { flex: 1 1 100%; }
.fa > span { font: 600 12px/1.2 'Inter', sans-serif; color: #cfcfcf; }
.fa input, .fa select { padding: 9px 11px; border: 1px solid var(--line); border-radius: 9px; background: #0f0f10; color: var(--text); font-size: 14px; color-scheme: dark; }
.fa .fp-in input { width: 100%; }
.fa-chk { align-self: center; }
.fdel { color: #f0a0a0; }
select { color-scheme: dark; }
.fadd h3 { margin: 0 0 8px; font-size: 13px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
.shours-wrap { display: flex; flex-wrap: wrap; gap: 22px; margin-top: 12px; }
.shours-wrap > div { flex: 1 1 280px; }
.shours { width: 100%; border-collapse: collapse; }
.shours th { text-align: left; padding: 6px 8px; font-size: 11px; color: var(--muted-2); text-transform: uppercase; letter-spacing: .5px; }
.shours td { padding: 5px 8px; border-bottom: 1px solid var(--line-soft); font-size: 13.5px; }
.shours input[type=time] { padding: 6px 8px; border: 1px solid var(--line); border-radius: 8px; background: #0f0f10; color: var(--text); font-size: 13px; color-scheme: dark; }
.fname a { font-weight: 600; font-size: 13.5px; }
.fopts { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: var(--card-2); border: 1px solid var(--line); color: var(--muted); font-size: 11px; }
.fa textarea { padding: 9px 11px; border: 1px solid var(--line); border-radius: 9px; background: #0f0f10; color: var(--text); font-size: 14px; font-family: inherit; resize: vertical; color-scheme: dark; }
.fgrp { margin: 14px 0; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--card); }
.fgrp-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.fgrp-head strong { font-size: 14.5px; color: var(--gold-light); }
.fgrp .ftable { min-width: 0; }
.fgrp .ftable td { border-bottom: 1px solid var(--line-soft); }
CSS;

// ---------- Insights tab ----------

/**
 * Horizontal bars, one gold series: label, bar from a common baseline, value at
 * the tip; the row's tooltip gives the share. $ranked = false keeps the given
 * order (days, time slots, sizes).
 */
function bars(array $counts, int $base, string $unit = 'questions'): string
{
    $counts = array_filter($counts, fn($n) => $n > 0) ?: [];
    if (!$counts) return '<p class="empty-sm">Nothing yet in this period.</p>';
    $max = max($counts);
    $out = '<ul class="bars">';
    foreach ($counts as $label => $n) {
        $pct = $base ? round($n * 100 / $base) : 0;
        $out .= '<li title="' . h("$label: $n $unit" . ($base ? " ($pct%)" : '')) . '"><span class="lbl">' . h($label) . '</span>'
            . '<span class="track"><span class="fill" style="width:' . max(2, round($n * 100 / $max)) . '%"></span></span>'
            . '<span class="val">' . $n . '</span></li>';
    }
    return $out . '</ul>';
}

/** Links to conversations, e.g. questions the assistant couldn't answer. */
function quote_list(array $items, int $days): string
{
    if (!$items) return '<p class="empty-sm">Nothing yet in this period.</p>';
    $out = '<ul class="quotes">';
    foreach ($items as $i) {
        $out .= '<li><a href="?c=' . rawurlencode($i['cid']) . '&amp;days=' . $days . '">&ldquo;' . h(mb_strimwidth($i['text'], 0, 120, '…')) . '&rdquo;</a>'
            . '<small>' . h(date('M j, g:i A', strtotime($i['time']))) . '</small></li>';
    }
    return $out . '</ul>';
}

function insights_html(array $in, int $days): string
{
    if (!$in['questions']) return '<p class="empty">No chats in this period yet.</p>';
    $p = $in['party'];
    $q = $in['questions'];
    $partyShare = $in['conversations'] ? round($p['convos'] * 100 / $in['conversations']) : 0;
    $avgSize = $p['size_list'] ? round(array_sum($p['size_list']) / count($p['size_list'])) : null;

    $panel = fn($title, $sub, $body, $wide = false) => '<section class="panel' . ($wide ? ' wide' : '') . '"><h2>' . h($title) . '</h2>'
        . ($sub ? '<p class="sub">' . $sub . '</p>' : '') . $body . '</section>';

    return '<div class="stats">'
        . '<div class="stat"><b>' . $in['conversations'] . '</b>conversations</div>'
        . '<div class="stat"><b>' . $q . '</b>questions</div>'
        . '<div class="stat"><b>' . $in['items_ordered'] . '</b>dishes ordered via chat</div>'
        . '<div class="stat"><b>' . $p['convos'] . '</b>private party inquiries</div>'
        . '<div class="stat"><b>' . count($in['unanswered']) . '</b>questions it couldn\'t answer</div>'
        . '</div><div class="grid">'
        . $panel('What guests ask about', 'Share of all questions; one question can touch several topics.', bars($in['topics'], $q))
        . $panel('Food guests ask about', 'Questions mentioning each kind of food.', bars($in['foods'], $q))
        . $panel('Dishes ordered through the chat', $in['ordering_convos'] . ' conversation' . ($in['ordering_convos'] === 1 ? '' : 's') . ' ordered food. Counts are portions.', bars($in['ordered'], $in['items_ordered'], 'ordered'))
        . $panel('Shows guests ask about', 'Shows the assistant showed a card for.', bars($in['shows'], 0, 'times'))
        . $panel('Days guests ask about', '"Tonight", "tomorrow" and "this weekend" count for the day they mean.', bars($in['days_asked'], 0, 'questions'))
        . $panel('When guests chat', 'Questions by the day and time they were asked (bar day, until 2 AM).',
            bars($in['chat_days'], $q) . '<div class="gap"></div>' . bars($in['chat_hours'], $q))
        . $panel('Private parties',
            $p['convos'] . ' conversation' . ($p['convos'] === 1 ? '' : 's') . " ($partyShare% of all) asked about a private event"
            . ($avgSize ? '; average group size ' . $avgSize : '') . '.',
            '<div class="minis"><div><b>' . $p['price_questions'] . '</b>price questions</div><div><b>' . $p['budget_convos'] . '</b>with budget worries</div>'
            . '<div><b>' . count($p['size_list']) . '</b>gave a group size</div></div>'
            . '<h3>Occasions</h3>' . bars($p['occasions'], $p['convos'], 'conversations')
            . '<h3>Group sizes</h3>' . bars($p['sizes'], count($p['size_list']), 'groups')
            . '<h3>Packages discussed</h3>' . bars($p['packages'], 0, 'mentions')
            . '<h3>Budget worries, in their words</h3>' . quote_list($p['budget_quotes'], $days), true)
        . $panel("Questions it couldn't answer", 'Add the missing facts to <code>data/chatbot-knowledge.md</code> and it will answer them next time.', quote_list($in['unanswered'], $days), true)
        . '</div>';
}

// ---------- sign in ----------

$password = (string) config()['chat_admin_password'];
if ($password === '') {
    page('Switched off', '<h1>Chat history is switched off</h1><p class="muted">Set <code>chat_admin_password</code> in <code>api/config.php</code> to turn this page on.</p>');
}

session_name('alibi_chats');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: chats.php');
    exit;
}

if (empty($_SESSION['chats_ok'])) {
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        rate_limit('chats-login', 10, 900); // slows down password guessing
        if (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['chats_ok'] = true;
            header('Location: chats.php');
            exit;
        }
        $error = '<p class="err">Wrong password.</p>';
    }
    page('Sign in', '<form class="login" method="post"><h1>Chat history</h1><p class="muted">Private page for the Alibi team.</p>'
        . $error . '<label>Password<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button type="submit">Sign in</button></form>');
}

// ---------- signed in ----------

chat_log_cleanup();
$days = in_array((int) ($_GET['days'] ?? 30), [1, 7, 30, 120], true) ? (int) ($_GET['days'] ?? 30) : 30;

// Optional exact date range (the date picker); overrides the day presets.
$reDate = '/^\d{4}-\d{2}-\d{2}$/';
$from = (isset($_GET['from']) && preg_match($reDate, (string) $_GET['from'])) ? (string) $_GET['from'] : null;
$to   = (isset($_GET['to']) && preg_match($reDate, (string) $_GET['to'])) ? (string) $_GET['to'] : null;
$rangeMode = ($from || $to);
if ($rangeMode) { $from = $from ?: $to; $to = $to ?: $from; if ($from > $to) [$from, $to] = [$to, $from]; }
$period = $rangeMode ? ('from=' . $from . '&amp;to=' . $to) : ('days=' . $days); // for internal links
$effFrom = $rangeMode ? $from : date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$effTo   = $rangeMode ? $to : date('Y-m-d');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// Food & menu tab (edit the Square menu locally: hide/price/pickup/surcharge/prep).
if (($_GET['view'] ?? '') === 'food') {
    $base = load_menu_base();
    $navF = '<nav class="views"><a href="?days=30">Conversations</a><a href="?days=30&amp;view=insights">Insights</a><a href="?view=food" class="on">Food</a><a href="?view=settings">Settings</a></nav>';

    // Per-item options editor (?view=food&item=<id>).
    if (isset($_GET['item'])) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ($base && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? '')) && ($_POST['op'] ?? '') === 'item') save_item_options($base);
            header('Location: ?view=food&item=' . rawurlencode((string) $_GET['item']) . '&saved=1');
            exit;
        }
        $iid = (string) $_GET['item'];
        if (!$base || !isset($base['items'][$iid])) page('Item', $navF . '<p><a href="?view=food">&larr; Food &amp; menu</a></p><p class="empty">Item not found. (Items you added are edited on the Food tab itself.)</p>');
        $ov = read_menu_overrides();
        page('Item', $navF . item_editor_html($base['items'][$iid], ($ov['items'] ?? [])[$iid] ?? [], $_SESSION['csrf'], isset($_GET['saved'])));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if ($base && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            $op = $_POST['op'] ?? 'items';
            if ($op === 'add') add_custom_item($base);
            elseif ($op === 'custom') save_custom_items($base);
            else save_food_overrides($base);
        }
        header('Location: ?view=food&saved=1'); // post/redirect/get: no resubmit on refresh
        exit;
    }
    if (!$base) page('Food', $navF . '<h1>Food &amp; menu</h1><p class="empty">The menu is temporarily unavailable. Please try again shortly.</p>');
    page('Food', $navF . food_html($base, read_menu_overrides(), $_SESSION['csrf'], isset($_GET['saved'])));
}

// Store settings tab (hours, delivery area, fees — edited locally).
if (($_GET['view'] ?? '') === 'settings') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) save_store_settings();
        header('Location: ?view=settings&saved=1');
        exit;
    }
    $menuS = load_menu();
    $store = $menuS ? ($menuS['store'] ?? []) : [];
    $navS = '<nav class="views"><a href="?days=30">Conversations</a><a href="?days=30&amp;view=insights">Insights</a><a href="?view=food">Food</a><a href="?view=settings" class="on">Settings</a></nav>';
    page('Settings', $navS . settings_html($store, $_SESSION['csrf'], isset($_GET['saved'])));
}

// Spreadsheet download.
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alibi-chats-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // lets Excel read accents and emoji
    fputcsv($out, ['conversation', 'name', 'email', 'ip', 'device', 'browser', 'distance (mi)', 'location', 'reservation asked', 'reservation party', 'time', 'guest asked', 'assistant replied', 'added to order', 'AI', 'input tokens', 'output tokens', 'seconds']);
    foreach (array_reverse(chat_log_conversations($days, $from, $to)) as $c) {
        $d = !empty($c['ip']) ? geo_distance($c['ip']) : null;
        $miles = $d ? round($d['miles']) : '';
        $place = $d ? $d['place'] : '';
        $rz = reservation_intent($c);
        $rzYes = $rz ? 'yes' : '';
        $rzParty = ($rz && $rz['party']) ? $rz['party'] : '';
        foreach ($c['turns'] as $t) {
            $row = [$c['cid'], $c['name'] ?? '', $c['email'] ?? '', $c['ip'] ?? '', $c['device'] ?? '', $c['browser'] ?? '', $miles, $place, $rzYes, $rzParty, $t['time'], $t['question'], $t['reply'], implode('; ', $t['ordered'] ?? []), trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? '')), $t['input_tokens'] ?? 0, $t['output_tokens'] ?? 0, $t['seconds'] ?? 0];
            // Guests' text must not run as spreadsheet formulas.
            fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row));
        }
    }
    exit;
}

$when = fn($iso) => date('M j, g:i A', strtotime($iso));

// One conversation.
if (isset($_GET['c'])) {
    $cid = chat_log_cid($_GET['c']);
    $all120 = chat_log_conversations(120);
    $c = $all120[$cid] ?? null;
    if (!$c) page('Not found', '<p><a href="chats.php?' . $period . '">&larr; All conversations</a></p><p class="empty">This conversation was not found (chats are kept for ' . (int) config()['chat_log_days'] . ' days).</p>');
    $ordered = array_merge(...array_map(fn($t) => $t['ordered'] ?? [], $c['turns']));
    $email = $c['email'] ?? '';
    $name = $c['name'] ?? '';
    $ip = $c['ip'] ?? '';
    $device = $c['device'] ?? '';
    $browser = $c['browser'] ?? '';
    $dist = $ip !== '' ? geo_distance($ip) : null;
    $who = trim(($name !== '' ? h($name) : '') . ($name !== '' && $email !== '' ? ' &middot; ' : '')
        . ($email !== '' ? '<a href="mailto:' . h($email) . '">' . h($email) . '</a>' : ''));
    // Device / browser / IP / approximate distance from the club.
    $chips = [];
    $db = trim($device . ($device && $browser ? ' &middot; ' : '') . $browser);
    if ($db !== '') $chips[] = '<span class="chip">' . h($device) . ($device && $browser ? ' &middot; ' : '') . h($browser) . '</span>';
    if ($ip !== '') $chips[] = '<span class="chip">IP ' . h($ip) . '</span>';
    if ($dist) $chips[] = '<span class="chip gold">~' . number_format($dist['miles'], $dist['miles'] < 10 ? 1 : 0) . ' mi from club' . ($dist['place'] !== '' ? ' &middot; ' . h($dist['place']) : '') . '</span>';
    // Earlier chats from the same email (returning guest).
    $prev = [];
    if ($email !== '') foreach ($all120 as $oc) {
        if (($oc['cid'] ?? '') !== $cid && strcasecmp(trim((string) ($oc['email'] ?? '')), $email) === 0) $prev[] = $oc;
    }
    $html = '<p><a href="chats.php?' . $period . '">&larr; All conversations</a></p>'
        . '<h1>Conversation ' . h($cid) . '</h1>'
        . ($who !== '' ? '<p class="who">' . $who . '</p>' : '<p class="muted">No contact details were given.</p>')
        . ($chips ? '<p class="chips">' . implode(' ', $chips) . '</p>' : '')
        . '<p class="muted">' . h($when($c['start'])) . ' &ndash; ' . h($when($c['end'])) . ' &middot; '
        . count($c['turns']) . ' question' . (count($c['turns']) === 1 ? '' : 's') . ($ordered ? ' &middot; <span class="tag">ordered ' . count($ordered) . ' item' . (count($ordered) === 1 ? '' : 's') . '</span>' : '') . '</p>';
    $rez = reservation_intent($c);
    if ($rez) {
        $html .= '<div class="resv"><strong>&#128197; Reservation request'
            . ($rez['party'] ? ' &middot; party of ' . (int) $rez['party'] : '') . '</strong>'
            . '<p>&ldquo;' . h($rez['quote']) . '&rdquo;</p>'
            . '<p class="muted">The assistant can\'t book tables. Follow up via the reservation form (/contact.html#reserve) or call (954) 565-2526.</p></div>';
    }
    if ($prev) {
        $html .= '<div class="prev"><strong>&#8617; Returning guest &mdash; ' . count($prev) . ' earlier ' . (count($prev) === 1 ? 'chat' : 'chats') . ' from ' . h($email) . '</strong><ul>';
        foreach ($prev as $oc) {
            $q = $oc['turns'][0]['question'] ?? '';
            $html .= '<li><a href="?c=' . rawurlencode($oc['cid']) . '&amp;' . $period . '">' . h($when($oc['end'])) . ' &middot; ' . count($oc['turns']) . ' Q</a> <span class="muted">' . h(mb_strimwidth($q, 0, 80, '…')) . '</span></li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '<div class="chat">';
    foreach ($c['turns'] as $t) {
        $html .= '<div class="msg guest">' . h($t['question']) . '</div><div class="meta r">Guest &middot; ' . h($when($t['time'])) . '</div>'
            // Markdown asterisks are removed as in the chat window (js/chat.js).
            . '<div class="msg bot">' . h(preg_replace(['/\*\*(.+?)\*\*/s', '/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/m'], ['$1', '$1$2'], $t['reply'])) . '</div>'
            . '<div class="meta">' . h(trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? ''))) . ' &middot; ' . h($t['seconds'] ?? '') . 's</div>';
        if (!empty($t['ordered'])) $html .= '<div class="order">&#128722; Added to order: ' . h(implode(', ', $t['ordered'])) . '</div>';
    }
    page('Conversation', $html . '</div>');
}

// The list, or the Insights tab.
$convos = chat_log_conversations($days, $from, $to);
$questions = array_sum(array_map(fn($c) => count($c['turns']), $convos));
$withOrders = count(array_filter($convos, fn($c) => array_filter($c['turns'], fn($t) => !empty($t['ordered']))));
$tokens = array_sum(array_map(fn($c) => array_sum(array_map(fn($t) => ($t['input_tokens'] ?? 0) + ($t['output_tokens'] ?? 0), $c['turns'])), $convos));
$view = ($_GET['view'] ?? '') === 'insights' ? 'insights' : 'list';

$tabs = '';
foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 120 => '120 days'] as $d => $label) {
    $on = (!$rangeMode && $d === $days) ? ' class="on"' : '';
    $tabs .= '<a href="?days=' . $d . ($view === 'insights' ? '&amp;view=insights' : '') . '"' . $on . '>' . $label . '</a>';
}
// Exact date range picker (submits ?from=&to=).
$today = date('Y-m-d');
$dateForm = '<form class="dates" method="get">' . ($view === 'insights' ? '<input type="hidden" name="view" value="insights">' : '')
    . '<label>From <input type="date" name="from" value="' . h($effFrom) . '" max="' . h($today) . '"></label>'
    . '<label>to <input type="date" name="to" value="' . h($effTo) . '" max="' . h($today) . '"></label>'
    . '<button type="submit">Apply</button>'
    . ($rangeMode ? '<a class="clear" href="?days=30' . ($view === 'insights' ? '&amp;view=insights' : '') . '">Clear</a>' : '') . '</form>';

$nav = '<nav class="views"><a href="?' . $period . '"' . ($view === 'list' ? ' class="on"' : '') . '>Conversations</a>'
    . '<a href="?' . $period . '&amp;view=insights"' . ($view === 'insights' ? ' class="on"' : '') . '>Insights</a>'
    . '<a href="?view=food">Food</a><a href="?view=settings">Settings</a></nav>';

if ($view === 'insights') {
    require_once __DIR__ . '/chat-insights.php';
    page('Insights', $nav . '<h1>Insights</h1><p class="muted">What guests ask about and order in the chat. Counted from their own words, so treat it as a guide rather than exact figures. Full sales are in Square.</p>'
        . '<div class="bar">' . $tabs . '</div>' . $dateForm . insights_html(chat_insights($convos), $days));
}

// Reservation requests (from the guests' own words) keyed by conversation.
$rezMap = [];
foreach ($convos as $cid => $c) { $r = reservation_intent($c); if ($r) $rezMap[$cid] = $r; }

// Stat-card icon (inline SVG).
$svg = fn($p) => '<div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg></div>';

$html = $nav . '<h1>Conversations</h1><p class="muted">Guests give an email (and optionally a name) before chatting; their IP address, device and browser are recorded too. Kept for ' . (int) config()['chat_log_days'] . ' days, then deleted automatically.</p>'
    . '<div class="bar">' . $tabs . '<a class="dl" href="?csv=1&amp;' . $period . '">Download spreadsheet</a></div>'
    . $dateForm
    . '<div class="stats">'
    . '<div class="stat">' . $svg('<path d="M21 11.5a8.38 8.38 0 0 1-11.8 7L4 20.5 5.5 15.6A8.38 8.38 0 1 1 21 11.5z"/>') . '<b>' . count($convos) . '</b>conversations</div>'
    . '<div class="stat">' . $svg('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>') . '<b>' . $questions . '</b>questions</div>'
    . '<div class="stat">' . $svg('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>') . '<b>' . count($rezMap) . '</b>reservation requests</div>'
    . '<div class="stat">' . $svg('<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>') . '<b>' . $withOrders . '</b>with food ordered</div>'
    . '<div class="stat">' . $svg('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>') . '<b>' . number_format($tokens) . '</b>AI tokens used</div>'
    . '</div>';

if (!$convos) {
    page('Conversations', $html . '<p class="empty">No chats in this period yet.</p>');
}
// Mark emails that appear more than once in this view as returning guests.
$emailCounts = [];
foreach ($convos as $c) { $e = strtolower(trim((string) ($c['email'] ?? ''))); if ($e !== '') $emailCounts[$e] = ($emailCounts[$e] ?? 0) + 1; }

$html .= '<div class="table"><table><thead><tr><th>Last message</th><th>Guest</th><th>Device</th><th>Distance</th><th>Questions</th><th>Ordered</th><th>First question</th><th></th></tr></thead><tbody>';
foreach ($convos as $c) {
    $items = count(array_merge(...array_map(fn($t) => $t['ordered'] ?? [], $c['turns'])));
    $first = $c['turns'][0]['question'] ?? '';
    $email = $c['email'] ?? '';
    $name = $c['name'] ?? '';
    $ecount = $email !== '' ? ($emailCounts[strtolower(trim($email))] ?? 1) : 1;
    $hasContact = ($name !== '' || $email !== '');
    $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : ($email !== '' ? mb_strtoupper(mb_substr($email, 0, 1)) : '?');
    $badges = '';
    if ($ecount > 1) $badges .= ' <span class="ret" title="' . $ecount . ' chats from this email in view">&#8617; returning</span>';
    if (isset($rezMap[$c['cid']])) $badges .= ' <span class="rez" title="Asked about a reservation">&#128197; Reservation</span>';
    $info = $hasContact
        ? ($name !== '' ? '<b>' . h($name) . '</b>' : '') . ($email !== '' ? '<a href="mailto:' . h($email) . '">' . h($email) . '</a>' : '') . $badges
        : '<span class="muted">No contact</span>' . $badges;
    $guest = '<div class="gcell"><span class="avatar' . ($hasContact ? '' : ' empty') . '">' . h($initial) . '</span><span class="ginfo">' . $info . '</span></div>';
    $device = (string) ($c['device'] ?? '');
    $browser = (string) ($c['browser'] ?? '');
    $devCell = $device !== '' ? h($device) . ($browser !== '' ? '<br><span class="muted">' . h($browser) . '</span>' : '') : '<span class="muted">&ndash;</span>';
    $d = !empty($c['ip']) ? geo_distance($c['ip']) : null;
    $distCell = $d ? '~' . number_format($d['miles'], $d['miles'] < 10 ? 1 : 0) . ' mi' . ($d['place'] !== '' ? '<br><span class="muted">' . h($d['place']) . '</span>' : '') : '<span class="muted">&ndash;</span>';
    $html .= '<tr><td class="nowrap">' . h($when($c['end'])) . '</td><td>' . $guest . '</td>'
        . '<td>' . $devCell . '</td><td>' . $distCell . '</td><td>' . count($c['turns']) . '</td>'
        . '<td>' . ($items ? '<span class="tag">' . $items . ' item' . ($items === 1 ? '' : 's') . '</span>' : '&ndash;') . '</td>'
        . '<td class="q">' . h(mb_strimwidth($first, 0, 90, '…')) . '</td>'
        . '<td><a href="?c=' . rawurlencode($c['cid']) . '&amp;' . $period . '">Read &rarr;</a></td></tr>';
}
page('Conversations', $html . '</tbody></table></div>');
