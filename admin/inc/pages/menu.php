<?php
/**
 * Menu & food: adjust the website's menu without touching Square. Hide an item,
 * change a price, limit it to pickup or delivery, add a delivery surcharge or a
 * longer prep time, edit its description and options, or add dishes of your own.
 *
 * Everything is saved as "local overrides" (api/data/menu-overrides.json, see
 * api/menu-overrides.php) that the website applies on top of the menu. Orders are
 * cash on delivery, so the price set here is exactly what the guest pays.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

// ---------- saving ----------

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
        if (!empty($_POST['delonly'][$id])) $o['deliveryOnly'] = true;
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

/** Category id → name, from the base menu. */
function food_categories(array $base): array
{
    $cats = [];
    foreach ($base['categories'] as $c) $cats[$c['id']] = $c['name'];
    return $cats;
}

/** Add a brand-new custom item from the "Add item" form; returns its name, or '' if nothing was added. */
function add_custom_item(array $base): string
{
    $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80));
    $price = dollars_to_cents((string) ($_POST['price'] ?? ''));
    if ($name === '' || $price === null) return ''; // need at least a name and price
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
        'deliveryOnly' => !empty($_POST['delonly']),
        'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['dfee'] ?? '')) ?? 0)),
        'prepMinutes'  => min(600, max(0, (int) ($_POST['prep'] ?? 0))),
    ];
    write_menu_overrides($ov);
    return $name;
}

/** Save edits and deletions to existing custom items; returns [edited, deleted]. */
function save_custom_items(array $base): array
{
    $cats = food_categories($base);
    $ov = read_menu_overrides();
    $prev = [];
    foreach ($ov['custom'] ?? [] as $c) if (!empty($c['id'])) $prev[(string) $c['id']] = $c;
    $out = [];
    $deleted = 0;
    foreach (array_map('strval', (array) ($_POST['cid'] ?? [])) as $id) {
        if (!isset($prev[$id])) continue;
        if (!empty($_POST['cdelete'][$id])) { $deleted++; continue; } // deleted
        $name = trim(mb_substr((string) ($_POST['cname'][$id] ?? ''), 0, 80));
        if ($name === '') continue;
        $catId = (string) ($_POST['ccat'][$id] ?? '');
        $out[] = [
            'id'           => $id,
            'name'         => $name,
            'description'  => trim(mb_substr((string) ($_POST['cdesc'][$id] ?? ($prev[$id]['description'] ?? '')), 0, 300)),
            'price'        => max(0, (int) (dollars_to_cents((string) ($_POST['cprice'][$id] ?? '')) ?? 0)),
            'category'     => $catId,
            'categoryName' => $cats[$catId] ?? 'More',
            'pickupOnly'   => !empty($_POST['cpickup'][$id]),
            'deliveryOnly' => !empty($_POST['cdelonly'][$id]),
            'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['cdfee'][$id] ?? '')) ?? 0)),
            'prepMinutes'  => min(600, max(0, (int) ($_POST['cprep'][$id] ?? 0))),
        ];
    }
    $ov['custom'] = $out;
    write_menu_overrides($ov);
    return [count($out), $deleted];
}

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

// ---------- pages ----------

function admin_page_menu(): void
{
    $base = load_menu_base();
    $itemId = get_str('item', 60);

    // The options editor for one item (?item=ID).
    if ($itemId !== '') {
        if (is_post()) {
            require_csrf();
            if ($base && ($_POST['op'] ?? '') === 'item' && isset($base['items'][(string) ($_POST['item'] ?? '')])) {
                save_item_options($base);
                admin_log('menu.item', $base['items'][(string) $_POST['item']]['name']);
                flash('Saved. The website menu is updated.');
            }
            redirect(admin_url('menu', ['item' => $itemId]));
        }
        admin_menu_item_page($base, $itemId); // ends the request
    }

    if (is_post()) {
        require_csrf();
        if ($base) {
            $op = $_POST['op'] ?? 'items';
            if ($op === 'add') {
                $name = add_custom_item($base);
                if ($name !== '') { admin_log('menu.add', $name); flash('Added ' . $name . '. It is on the website menu now.'); }
                else flash('Give the new item a name and a price.', 'err');
            } elseif ($op === 'custom') {
                [$edited, $deleted] = save_custom_items($base);
                admin_log('menu.custom', $edited . ' saved, ' . $deleted . ' deleted');
                flash('Saved your added items.' . ($deleted ? ' ' . plural($deleted, 'item') . ' deleted.' : ''));
            } elseif (empty($_POST['end'])) {
                // The server drops form fields past its max_input_vars limit; saving a cut-off form would lose settings.
                flash('Nothing was saved: the menu form is bigger than this server accepts (max_input_vars is ' . ini_get('max_input_vars') . '). Ask your web host to raise it.', 'err');
            } else {
                save_food_overrides($base);
                admin_log('menu.items', 'prices, availability and fulfillment');
                flash('Saved. The website menu is updated.');
            }
        }
        redirect(admin_url('menu'));
    }

    if (!$base) {
        admin_render('Menu & food', page_head('Menu & food') . empty_state('The menu is temporarily unavailable', 'Please try again in a moment.', 'list'), 'menu');
    }
    admin_menu_list_page($base);
}

function admin_menu_list_page(array $base): void
{
    $over = read_menu_overrides();
    $ov = $over['items'] ?? [];
    $storePrep = (int) ($base['store']['prepMinutes'] ?? 25);
    $csrf = csrf_field();
    $money = fn($c) => '$' . number_format($c / 100, 2);

    // Category options for the forms.
    $cats = food_categories($base);
    $catOptions = fn($sel) => implode('', array_map(fn($id) => '<option value="' . h($id) . '"' . ((string) $id === $sel ? ' selected' : '') . '>' . h($cats[$id]) . '</option>', array_keys($cats)));

    // The menu, category by category.
    $rows = '';
    $count = 0;
    $hidden = 0;
    foreach ($base['categories'] as $cat) {
        $items = '';
        foreach ($cat['itemIds'] as $id) {
            $it = $base['items'][$id] ?? null;
            if (!$it) continue;
            $count++;
            $o = $ov[$id] ?? [];
            $off = !empty($o['hidden']);
            if ($off) $hidden++;
            $prices = '';
            foreach ($it['variations'] as $v) {
                $cur = $o['prices'][$v['id']] ?? $v['price'];
                $prices .= '<label class="fp"><span class="fp-lbl">' . ($v['name'] !== 'Regular' ? h($v['name']) : 'Price') . '</span>'
                    . money_input('price[' . $id . '][' . $v['id'] . ']', number_format($cur / 100, 2, '.', ''))
                    . '<small>Base price ' . h($money($v['price'])) . '</small></label>';
            }
            $items .= '<tr class="' . ($off ? 'is-off' : '') . '" data-name="' . h(mb_strtolower($it['name'])) . '">'
                . '<td><label class="switch"><input type="checkbox" name="avail[' . h($id) . ']" value="1" ' . ($off ? '' : 'checked') . ' aria-label="Show ' . h($it['name']) . ' on the website"><span></span></label>'
                . '<input type="hidden" name="ids[]" value="' . h($id) . '"></td>'
                . '<td class="fname"><a href="' . h(admin_url('menu', ['item' => $id])) . '" title="Edit description and options">' . h($it['name']) . '</a>'
                . (!empty($it['soldOut']) ? ' <span class="badge tone-crit">sold out</span>' : '')
                . (($it['modifiers'] ?? []) ? ' <span class="chip">' . plural(count($it['modifiers']), 'option') . '</span>' : '') . '</td>'
                . '<td><div class="fprices">' . $prices . '</div></td>'
                . '<td><label class="check"><input type="checkbox" name="pickup[' . h($id) . ']" value="1" ' . (!empty($o['pickupOnly']) ? 'checked' : '') . '> Pickup only</label>'
                . '<label class="check"><input type="checkbox" name="delonly[' . h($id) . ']" value="1" ' . (!empty($o['deliveryOnly']) ? 'checked' : '') . '> Delivery only</label></td>'
                . '<td>' . money_input('dfee[' . $id . ']', !empty($o['deliveryFee']) ? number_format($o['deliveryFee'] / 100, 2, '.', '') : '', '0.00') . '</td>'
                . '<td>' . minutes_input('prep[' . $id . ']', !empty($o['prepMinutes']) ? (string) (int) $o['prepMinutes'] : '', (string) $storePrep) . '</td></tr>';
        }
        if ($items !== '') $rows .= '<tr class="cat"><td colspan="6">' . h($cat['name']) . '</td></tr>' . $items;
    }

    // "Add a new item".
    $add = '<details class="card addcard"' . (get_str('add', 1) === '1' ? ' open' : '') . '><summary><span>' . icon('plus', 16) . ' Add a new item</span><small>Your own dishes, orderable on the website</small></summary><div class="card-body">'
        . '<form method="post" action="' . h(admin_url('menu')) . '">' . $csrf . '<input type="hidden" name="op" value="add">'
        . '<div class="form-grid">'
        . '<label class="field"><span>Name</span><input name="name" maxlength="80" required></label>'
        . '<label class="field sm"><span>Price</span>' . money_input('price', '', '', true) . '</label>'
        . '<label class="field sm"><span>Category</span><select name="category">' . $catOptions('') . '</select></label>'
        . '<label class="field sm"><span>Delivery surcharge</span>' . money_input('dfee', '', '0.00') . '</label>'
        . '<label class="field sm"><span>Prep time</span>' . minutes_input('prep', '', (string) $storePrep) . '</label>'
        . '<label class="field wide"><span>Description (optional)</span><input name="description" maxlength="300"></label>'
        . '<label class="check"><input type="checkbox" name="pickup" value="1"> Pickup only</label>'
        . '<label class="check"><input type="checkbox" name="delonly" value="1"> Delivery only</label>'
        . '</div><div class="bar">' . btn_submit('Add item', 'primary', '', '', 'plus') . '</div></form></div></details>';

    // Items you added.
    $customRows = '';
    foreach ($over['custom'] ?? [] as $c) {
        $id = (string) ($c['id'] ?? '');
        if ($id === '') continue;
        $customRows .= '<tr><td><input name="cname[' . h($id) . ']" value="' . h($c['name'] ?? '') . '" maxlength="80" aria-label="Name">'
            . '<input type="hidden" name="cid[]" value="' . h($id) . '"><input type="hidden" name="cdesc[' . h($id) . ']" value="' . h($c['description'] ?? '') . '"></td>'
            . '<td>' . money_input('cprice[' . $id . ']', number_format((int) ($c['price'] ?? 0) / 100, 2, '.', '')) . '</td>'
            . '<td><select name="ccat[' . h($id) . ']" aria-label="Category">' . $catOptions((string) ($c['category'] ?? '')) . '</select></td>'
            . '<td><label class="check"><input type="checkbox" name="cpickup[' . h($id) . ']" value="1" ' . (!empty($c['pickupOnly']) ? 'checked' : '') . '> Pickup only</label>'
            . '<label class="check"><input type="checkbox" name="cdelonly[' . h($id) . ']" value="1" ' . (!empty($c['deliveryOnly']) ? 'checked' : '') . '> Delivery only</label></td>'
            . '<td>' . money_input('cdfee[' . $id . ']', !empty($c['deliveryFee']) ? number_format($c['deliveryFee'] / 100, 2, '.', '') : '', '0.00') . '</td>'
            . '<td>' . minutes_input('cprep[' . $id . ']', !empty($c['prepMinutes']) ? (string) (int) $c['prepMinutes'] : '', (string) $storePrep) . '</td>'
            . '<td><label class="check danger"><input type="checkbox" name="cdelete[' . h($id) . ']" value="1"> Delete</label></td></tr>';
    }
    $custom = $customRows === '' ? '' : card('Items you added',
        '<form method="post" action="' . h(admin_url('menu')) . '">' . $csrf . '<input type="hidden" name="op" value="custom">'
        . '<div class="table flush"><table class="tbl ftable"><thead><tr><th>Name</th><th>Price</th><th>Category</th><th>Fulfillment</th><th>Delivery surcharge</th><th>Prep time</th><th></th></tr></thead><tbody>' . $customRows . '</tbody></table></div>'
        . '<div class="bar">' . btn_submit('Save added items', 'primary') . '</div></form>');

    $body = page_head('Menu & food', 'Your menu for the website. Orders are cash on delivery, so the price you set is what the guest pays. Turn an item off to hide it; a blank price keeps the base price; a blank prep time uses the store default (' . $storePrep . ' min).',
            btn_link('View menu page', SITE_BASE . 'menu.html', 'sm', 'external', ' target="_blank" rel="noopener"'))
        . $add . $custom
        . '<form class="menuform" method="post" action="' . h(admin_url('menu')) . '">' . $csrf . '<input type="hidden" name="op" value="items">'
        . '<div class="toolbar">' . '<label class="search">' . icon('search', 16) . '<input type="search" data-filter="#food-table" placeholder="Filter items…" aria-label="Filter items"></label>'
        . '<span class="grow"></span><span class="muted" data-filter-count="#food-table">' . plural($count, 'item') . ($hidden ? ', ' . $hidden . ' hidden' : '') . '</span></div>'
        . '<div class="table"><table class="tbl ftable" id="food-table"><thead><tr><th>On</th><th>Item</th><th>Price</th><th>Fulfillment</th><th>Delivery surcharge</th><th>Prep time</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<input type="hidden" name="end" value="1">'
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save changes', 'primary', '', '', 'check') . '</div></form>';
    admin_render('Menu & food', $body, 'menu');
}

/** The per-item editor: description + each option group's choices (re-price / hide). Ends the request. */
function admin_menu_item_page(?array $base, string $id): void
{
    $listLink = '<p class="back"><a href="' . h(admin_url('menu')) . '">' . icon('left', 15) . ' Menu &amp; food</a></p>';
    if (!$base || !isset($base['items'][$id])) {
        admin_render('Menu item', $listLink . empty_state('Item not found', 'Items you added yourself are edited on the Menu &amp; food page itself.', 'list'), 'menu');
    }
    $item = $base['items'][$id];
    $o = (read_menu_overrides()['items'] ?? [])[$id] ?? [];
    $hideG = array_flip($o['hideGroups'] ?? []);
    $hideC = array_flip($o['hideChoices'] ?? []);
    $cp = $o['choicePrices'] ?? [];
    $desc = $o['description'] ?? ($item['description'] ?? '');

    $groups = '';
    foreach ($item['modifiers'] as $g) {
        $choices = '';
        foreach ($g['choices'] as $c) {
            $price = $cp[$c['id']] ?? $c['price'];
            $choices .= '<tr><td>' . h($c['name']) . '</td>'
                . '<td>' . money_input('chprice[' . $c['id'] . ']', number_format((int) $price / 100, 2, '.', '')) . ' <small class="muted">Base price $' . number_format((int) $c['price'] / 100, 2) . '</small></td>'
                . '<td><label class="check"><input type="checkbox" name="hidechoice[]" value="' . h($c['id']) . '" ' . (isset($hideC[$c['id']]) ? 'checked' : '') . '> Hide</label></td></tr>';
        }
        $groups .= '<section class="fgrp"><div class="fgrp-head"><strong>' . h($g['name']) . '</strong>'
            . '<label class="check danger"><input type="checkbox" name="hidegroup[]" value="' . h($g['id']) . '" ' . (isset($hideG[$g['id']]) ? 'checked' : '') . '> Hide whole group</label></div>'
            . '<div class="table flush"><table class="tbl"><tbody>' . $choices . '</tbody></table></div></section>';
    }
    if ($groups === '') $groups = '<p class="muted">This item has no options to edit.</p>';

    $body = $listLink . page_head($item['name'], 'Change the description guests see, and re-price or hide individual options.')
        . '<form method="post" action="' . h(admin_url('menu', ['item' => $id])) . '">' . csrf_field() . '<input type="hidden" name="op" value="item"><input type="hidden" name="item" value="' . h($id) . '">'
        . card('Description', '<label class="field wide"><span class="sr">Description</span><textarea name="description" maxlength="300" rows="3">' . h($desc) . '</textarea></label>')
        . '<h2 class="sect">Options</h2><p class="muted">Re-price or hide individual choices, or hide a whole group.</p>' . $groups
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save item', 'primary', '', '', 'check') . '</div></form>';
    admin_render($item['name'], $body, 'menu');
}
