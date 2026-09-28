<?php
/**
 * Local menu overrides for the Food admin tab (api/chats.php?view=food).
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

function menu_overrides_file(): string
{
    return data_dir() . '/menu-overrides.json';
}

/** The saved overrides, as ['items' => [itemId => [...]]]. */
function read_menu_overrides(): array
{
    $data = json_decode((string) @file_get_contents(menu_overrides_file()), true);
    return is_array($data) && isset($data['items']) && is_array($data['items']) ? $data : ['items' => []];
}

function write_menu_overrides(array $data): bool
{
    $dir = data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return (bool) @file_put_contents(menu_overrides_file(),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Apply the saved overrides to a menu array (from load_menu_base): drop hidden
 * items, override prices, and attach pickupOnly / deliveryFee / prepMinutes so
 * checkout and the front-end can honour them.
 */
function apply_menu_overrides(array $menu): array
{
    if (empty($menu['items'])) return $menu;
    $items = read_menu_overrides()['items'];
    if ($items) {
        foreach ($menu['items'] as $id => &$item) {
            $o = $items[$id] ?? null;
            if (!$o) continue;
            if (!empty($o['hidden'])) { unset($menu['items'][$id]); continue; }
            if (!empty($o['prices']) && is_array($o['prices'])) {
                foreach ($item['variations'] as &$v) {
                    if (isset($o['prices'][$v['id']])) $v['price'] = max(0, (int) $o['prices'][$v['id']]);
                }
                unset($v);
            }
            if (!empty($o['pickupOnly']))  $item['pickupOnly'] = true;
            if (!empty($o['deliveryFee'])) $item['deliveryFee'] = max(0, (int) $o['deliveryFee']);
            if (!empty($o['prepMinutes'])) $item['prepMinutes'] = max(0, (int) $o['prepMinutes']);
        }
        unset($item);
        // Drop hidden items from their categories, then drop empty categories.
        foreach ($menu['categories'] as &$c) {
            $c['itemIds'] = array_values(array_filter($c['itemIds'], fn($id) => isset($menu['items'][$id])));
        }
        unset($c);
        $menu['categories'] = array_values(array_filter($menu['categories'], fn($c) => $c['itemIds']));
    }
    return $menu;
}
