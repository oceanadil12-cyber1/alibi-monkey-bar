<?php
/**
 * Local menu overrides for the admin's Menu & food page (admin/?page=menu).
 *
 * The food menu itself comes live from Square (api/menu.php). This file lets the
 * restaurant adjust it locally, on top of Square, without touching Square:
 *   - hide an item from the website (enable/disable)
 *   - override a variation's price
 *   - mark an item pickup-only (blocked from delivery orders)
 *   - add a per-item delivery surcharge (cents) and a per-item prep time (minutes)
 *
 * Because orders are cash on delivery (no Square charge), the price shown here is
 * exactly what the guest pays, so local price changes are safe. Stored privately
 * in api/data/menu-overrides.json (never committed).
 */
require_once __DIR__ . '/lib.php';

/** The saved overrides, as ['items' => [itemId => [...]]]. */
function read_menu_overrides(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $data = db_setting('menu_overrides');
    } catch (Throwable $e) {
        $data = null;
    }
    return is_array($data) && isset($data['items']) && is_array($data['items']) ? $data : ['items' => []];
}

function write_menu_overrides(array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('menu_overrides', $data);
        return true;
    } catch (Throwable $e) {
        error_log('write_menu_overrides: ' . $e->getMessage());
        return false;
    }
}

// ---------- store settings (hours, delivery, fees) ----------

/** Locally-edited store settings (empty = use the frozen snapshot's values). */
function read_store_settings(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $d = db_setting('store_settings');
    } catch (Throwable $e) {
        $d = null;
    }
    return is_array($d) ? $d : [];
}

function write_store_settings(array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('store_settings', $data);
        return true;
    } catch (Throwable $e) {
        error_log('write_store_settings: ' . $e->getMessage());
        return false;
    }
}

/** Merge the locally-edited store settings over the menu's store block. */
function apply_store_settings(array $menu): array
{
    $s = read_store_settings();
    if ($s && !empty($menu['store'])) $menu['store'] = array_merge($menu['store'], $s);
    return $menu;
}

/** Build a menu item (in the load_menu shape) from a saved custom-item entry. */
function custom_menu_item(array $c): ?array
{
    if (empty($c['id']) || empty($c['name'])) return null;
    $id = (string) $c['id'];
    $item = [
        'id'          => $id,
        'squareId'    => null,
        'name'        => (string) $c['name'],
        'description' => (string) ($c['description'] ?? ''),
        'image'       => null,
        'soldOut'     => false,
        'maxQty'      => 0,
        'variations'  => [['id' => $id . '-v', 'name' => 'Regular', 'price' => max(0, (int) ($c['price'] ?? 0)), 'soldOut' => false]],
        'modifiers'   => [],
        'custom'      => true,
    ];
    if (!empty($c['pickupOnly']))  $item['pickupOnly'] = true;
    if (!empty($c['deliveryOnly'])) $item['deliveryOnly'] = true;
    if (!empty($c['deliveryFee'])) $item['deliveryFee'] = max(0, (int) $c['deliveryFee']);
    if (!empty($c['prepMinutes'])) $item['prepMinutes'] = max(0, (int) $c['prepMinutes']);
    return $item;
}

/**
 * Apply the saved overrides to a menu array (from load_menu_base): drop hidden
 * items, override prices, attach pickupOnly / deliveryFee / prepMinutes, and add
 * the restaurant's own custom items (not in Square) so checkout and the
 * front-end treat them like any other dish.
 */
function apply_menu_overrides(array $menu): array
{
    $menu = apply_store_settings($menu); // hours, delivery, fees
    if (empty($menu['items'])) return $menu;
    $ov = read_menu_overrides();

    foreach ($menu['items'] as $id => &$item) {
        $o = ($ov['items'] ?? [])[$id] ?? null;
        if (!$o) continue;
        if (!empty($o['hidden'])) { unset($menu['items'][$id]); continue; }
        if (!empty($o['prices']) && is_array($o['prices'])) {
            foreach ($item['variations'] as &$v) {
                if (isset($o['prices'][$v['id']])) $v['price'] = max(0, (int) $o['prices'][$v['id']]);
            }
            unset($v);
        }
        if (!empty($o['pickupOnly']))  $item['pickupOnly'] = true;
        if (!empty($o['deliveryOnly'])) $item['deliveryOnly'] = true;
        if (!empty($o['deliveryFee'])) $item['deliveryFee'] = max(0, (int) $o['deliveryFee']);
        if (!empty($o['prepMinutes'])) $item['prepMinutes'] = max(0, (int) $o['prepMinutes']);
        if (isset($o['description']) && $o['description'] !== '') $item['description'] = (string) $o['description'];

        // Option-group edits: hide groups/choices and re-price choices.
        if (!empty($item['modifiers']) && (!empty($o['hideGroups']) || !empty($o['hideChoices']) || !empty($o['choicePrices']))) {
            $hideG = array_flip($o['hideGroups'] ?? []);
            $hideC = array_flip($o['hideChoices'] ?? []);
            $cp = $o['choicePrices'] ?? [];
            $groups = [];
            foreach ($item['modifiers'] as $g) {
                if (isset($hideG[$g['id']])) continue;
                $choices = [];
                foreach ($g['choices'] as $c) {
                    if (isset($hideC[$c['id']])) continue;
                    if (isset($cp[$c['id']])) $c['price'] = max(0, (int) $cp[$c['id']]);
                    $choices[] = $c;
                }
                if (!$choices) continue;
                $g['choices'] = $choices;
                if ($g['min'] > count($choices)) $g['min'] = count($choices);
                $groups[] = $g;
            }
            $item['modifiers'] = $groups;
        }
    }
    unset($item);

    // Custom items: add to the menu and to their chosen category (created if gone).
    foreach ($ov['custom'] ?? [] as $c) {
        $item = custom_menu_item((array) $c);
        if (!$item) continue;
        $id = $item['id'];
        $menu['items'][$id] = $item;
        $catId = (string) ($c['category'] ?? '');
        $placed = false;
        foreach ($menu['categories'] as &$cat) {
            if ($cat['id'] === $catId) { $cat['itemIds'][] = $id; $placed = true; break; }
        }
        unset($cat);
        if (!$placed) $menu['categories'][] = ['id' => $catId ?: 'extras', 'name' => (string) ($c['categoryName'] ?? 'More'), 'itemIds' => [$id]];
    }

    // Drop hidden items from their categories, then drop empty categories.
    foreach ($menu['categories'] as &$c) {
        $c['itemIds'] = array_values(array_filter($c['itemIds'], fn($id) => isset($menu['items'][$id])));
    }
    unset($c);
    $menu['categories'] = array_values(array_filter($menu['categories'], fn($c) => $c['itemIds']));
    return $menu;
}
