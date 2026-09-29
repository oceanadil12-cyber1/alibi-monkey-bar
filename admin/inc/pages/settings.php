<?php
/**
 * Store settings: hours, delivery area and fees for the website's ordering.
 * Saved in api/data/store-settings.json (see api/menu-overrides.php) and applied
 * on top of the menu, so checkout, the menu page and the chat all follow them.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

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

function admin_page_settings(): void
{
    if (is_post()) {
        require_csrf();
        save_store_settings();
        admin_log('settings.save', 'hours, delivery and fees');
        flash('Saved. The website follows the new settings straight away.');
        redirect(admin_url('settings'));
    }

    $menu = load_menu();
    $store = $menu ? ($menu['store'] ?? []) : [];
    $days = ['MON' => 'Monday', 'TUE' => 'Tuesday', 'WED' => 'Wednesday', 'THU' => 'Thursday', 'FRI' => 'Friday', 'SAT' => 'Saturday', 'SUN' => 'Sunday'];
    $money = fn($c) => number_format(((int) $c) / 100, 2, '.', '');
    $timeVal = fn(array $hours, string $d, string $which) => isset($hours[$d][0][$which]) ? substr((string) $hours[$d][0][$which], 0, 5) : '';
    $hoursTable = function (string $prefix, array $hours) use ($days, $timeVal) {
        $rows = '';
        foreach ($days as $k => $name) {
            $rows .= '<tr><td>' . $name . '</td>'
                . '<td><input type="time" name="' . $prefix . '_open[' . $k . ']" value="' . h($timeVal($hours, $k, 'open')) . '" aria-label="' . $name . ' opens"></td>'
                . '<td><input type="time" name="' . $prefix . '_close[' . $k . ']" value="' . h($timeVal($hours, $k, 'close')) . '" aria-label="' . $name . ' closes"></td></tr>';
        }
        return '<div class="table flush"><table class="tbl hours"><thead><tr><th>Day</th><th>Open</th><th>Close</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    };

    $body = page_head('Store settings', 'Hours, delivery area and fees for the website\'s ordering. Leave a day\'s times blank to mark it closed.')
        . '<form method="post" action="' . h(admin_url('settings')) . '">' . csrf_field()
        . card('Business', '<div class="form-grid">'
            . '<label class="field"><span>Name</span><input name="name" value="' . h($store['name'] ?? '') . '" maxlength="80"></label>'
            . '<label class="field"><span>Address</span><input name="address" value="' . h($store['address'] ?? '') . '" maxlength="160"></label>'
            . '<label class="field sm"><span>Phone</span><input name="phone" value="' . h($store['phone'] ?? '') . '" maxlength="40"></label></div>')
        . card('Ordering & delivery', '<div class="form-grid">'
            . '<label class="field sm"><span>Prep time</span>' . minutes_input('prep', (string) (int) ($store['prepMinutes'] ?? 25)) . '</label>'
            . '<label class="field sm"><span>Pickup minimum</span>' . money_input('pickupMin', $money($store['pickupMinimum'] ?? 0)) . '</label>'
            . '<label class="check"><input type="checkbox" name="deliveryEnabled" value="1" ' . (!empty($store['deliveryEnabled']) ? 'checked' : '') . '> Delivery enabled</label>'
            . '<label class="field sm"><span>Delivery fee</span>' . money_input('deliveryFee', $money($store['deliveryFee'] ?? 0)) . '</label>'
            . '<label class="field sm"><span>Delivery minimum</span>' . money_input('deliveryMin', $money($store['deliveryMinimum'] ?? 0)) . '</label>'
            . '<label class="field"><span>Delivery ZIP codes (comma separated)</span><input name="deliveryZips" value="' . h(implode(', ', $store['deliveryZips'] ?? [])) . '"></label>'
            . '<label class="field wide"><span>Pickup instructions (optional)</span><input name="pickupInstructions" value="' . h($store['pickupInstructions'] ?? '') . '" maxlength="300"></label></div>')
        . card('Hours', '<div class="hours-wrap"><div><h3>Pickup</h3>' . $hoursTable('pickup', $store['pickupHours'] ?? []) . '</div>'
            . '<div><h3>Delivery</h3>' . $hoursTable('delivery', $store['deliveryHours'] ?? []) . '</div></div>')
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save settings', 'primary', '', '', 'check') . '</div></form>';
    admin_render('Store settings', $body, 'settings');
}
