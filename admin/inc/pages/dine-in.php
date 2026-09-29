<?php
/**
 * Dine-In: waiter ordering interface for in-house guests.
 *
 * Select a table, browse the menu, build an order, add special instructions,
 * and submit — the order appears on the Kitchen Display System automatically.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_dine_in(): void
{
    $menu = load_menu();
    $categories = [];
    $items = [];
    if ($menu && !empty($menu['items'])) {
        foreach ($menu['items'] as $id => $item) {
            if (!empty($item['soldOut']) || !empty($item['hidden'])) continue;
            $cat = $item['category'] ?? 'Other';
            if (!isset($categories[$cat])) $categories[$cat] = [];
            $categories[$cat][] = $id;
            $items[$id] = $item;
        }
    }

    $menuJson = json_encode([
        'categories' => $categories,
        'items' => array_map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'variations' => array_map(fn($v) => [
                    'id' => $v['id'],
                    'name' => $v['name'],
                    'price' => $v['price'],
                    'soldOut' => !empty($v['soldOut']),
                ], $item['variations'] ?? []),
                'modifiers' => array_map(fn($g) => [
                    'name' => $g['name'],
                    'min' => $g['min'],
                    'max' => $g['max'],
                    'choices' => array_map(fn($c) => [
                        'id' => $c['id'],
                        'name' => $c['name'],
                        'price' => $c['price'],
                        'soldOut' => !empty($c['soldOut']),
                    ], $g['choices'] ?? []),
                ], $item['modifiers'] ?? []),
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $body = page_head('Dine-In Order',
        'Create an order for a table. It will appear on the Kitchen Display automatically.',
        btn_link('Open Kitchen Display', '../kds/', '', 'external', ' target="_blank"'))
    . '<div id="dine-in-app" data-menu=\'' . h($menuJson) . '\' data-api="' . h(admin_url('', ['api' => 'dine-in'])) . '">'
    . '<div class="split">'

    // left: menu
    . '<div class="stack" id="dinein-menu-col">'
    .   '<div class="card">'
    .     '<header class="card-head"><h2>Menu</h2><div id="dinein-cat-tabs" class="tabs"></div></header>'
    .     '<div class="card-body"><div id="dinein-items" class="dinein-items"></div></div>'
    .   '</div>'
    . '</div>'

    // right: order
    . '<div class="stack" id="dinein-order-col">'
    .   '<div class="card">'
    .     '<header class="card-head"><h2>Order</h2></header>'
    .     '<div class="card-body">'
    .       '<label class="field"><span>Table Number</span>'
    .         '<input type="number" id="dinein-table" min="1" max="999" placeholder="Enter table #" required>'
    .       '</label>'
    .       '<div id="dinein-cart" class="dinein-cart">'
    .         '<p class="muted" id="dinein-cart-empty">No items added yet. Select items from the menu.</p>'
    .       '</div>'
    .       '<label class="field"><span>Special Instructions / Kitchen Notes</span>'
    .         '<textarea id="dinein-notes" rows="2" maxlength="500" placeholder="Allergies, cooking preferences, etc."></textarea>'
    .       '</label>'
    .       '<div class="bar">'
    .         '<span id="dinein-total" class="dinein-total"></span>'
    .         '<button class="btn primary" id="dinein-submit" disabled>'
    .           icon('check', 16) . '<span>Send to Kitchen</span>'
    .         '</button>'
    .       '</div>'
    .       '<div id="dinein-result" hidden></div>'
    .     '</div>'
    .   '</div>'
    . '</div>'

    . '</div></div>';

    admin_render('Dine-In', $body, 'dine-in', ['dine-in.js']);
}
