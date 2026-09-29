<?php
/**
 * Happy Hour: the menu, its time, and the scrolling ticker across the site.
 * Saved in api/data/happy-hour.json (see api/happy-hour.php), which the menu page,
 * the ticker and the chat assistant all read.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** "$6.99" / "6" → "6.99" / "6" (digits and one dot); '' otherwise. */
function hh_clean_price(string $s): string
{
    $s = preg_replace('/[^0-9.]/', '', $s);
    return preg_match('/^\d+(\.\d{1,2})?$/', $s) ? $s : '';
}

/** "16:00" kept; invalid → fallback. */
function hh_clean_time(string $s, string $fallback): string
{
    return preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m) && (int) $m[1] < 24 && (int) $m[2] < 60
        ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : $fallback;
}

/** Save the Happy Hour form to happy-hour.json. */
function save_happy_hour(): void
{
    $items = [];
    foreach ((array) ($_POST['hhname'] ?? []) as $k => $n) {
        $n = trim(mb_substr((string) $n, 0, 60));
        if ($n === '') continue;
        $items[] = ['name' => $n, 'desc' => trim(mb_substr((string) ($_POST['hhdesc'][$k] ?? ''), 0, 90)), 'price' => hh_clean_price((string) ($_POST['hhprice'][$k] ?? ''))];
    }
    $sides = [];
    foreach ((array) ($_POST['sidename'] ?? []) as $k => $n) {
        $n = trim(mb_substr((string) $n, 0, 60));
        if ($n === '') continue;
        $sides[] = ['name' => $n, 'price' => hh_clean_price((string) ($_POST['sideprice'][$k] ?? ''))];
    }
    write_happy_hour([
        'enabled'       => !empty($_POST['enabled']),
        'tickerEnabled' => !empty($_POST['tickerEnabled']),
        'start'         => hh_clean_time((string) ($_POST['start'] ?? ''), '16:00'),
        'end'           => hh_clean_time((string) ($_POST['end'] ?? ''), '18:45'),
        'note'          => trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 200)),
        'items'         => $items,
        'sides'         => $sides,
    ]);
}

function admin_page_happy_hour(): void
{
    if (is_post()) {
        require_csrf();
        save_happy_hour();
        admin_log('happy.save', 'menu, times and ticker');
        flash('Saved. The website shows the new Happy Hour on the next page load.');
        redirect(admin_url('happy-hour'));
    }

    $hh = happy_hour_data();
    // Existing rows plus a few blank ones to add more.
    $items = $hh['items'];
    for ($i = 0; $i < 3; $i++) $items[] = ['name' => '', 'desc' => '', 'price' => ''];
    $sides = $hh['sides'];
    for ($i = 0; $i < 2; $i++) $sides[] = ['name' => '', 'price' => ''];

    $itemRows = '';
    foreach ($items as $k => $it) {
        $itemRows .= '<tr><td><input name="hhname[' . $k . ']" value="' . h($it['name']) . '" maxlength="60" placeholder="Item name" aria-label="Item name"></td>'
            . '<td><input name="hhdesc[' . $k . ']" value="' . h($it['desc']) . '" maxlength="90" placeholder="Short description (optional)" aria-label="Description"></td>'
            . '<td>' . money_input('hhprice[' . $k . ']', (string) $it['price'], '0') . '</td></tr>';
    }
    $sideRows = '';
    foreach ($sides as $k => $s) {
        $sideRows .= '<tr><td><input name="sidename[' . $k . ']" value="' . h($s['name']) . '" maxlength="60" placeholder="Side name" aria-label="Side name"></td>'
            . '<td>' . money_input('sideprice[' . $k . ']', (string) $s['price'], '0') . '</td></tr>';
    }

    $body = page_head('Happy Hour', 'The Happy Hour menu, its time, and the scrolling ticker across the site. Leave an item\'s name blank to drop it; the blank rows at the end are for adding new items. Prices in dollars.',
            btn_link('View menu page', SITE_BASE . 'menu.html#happy-hour', 'sm', 'external', ' target="_blank" rel="noopener"'))
        . '<form method="post" action="' . h(admin_url('happy-hour')) . '">' . csrf_field()
        . card('Settings', '<div class="form-grid">'
            . '<label class="check"><input type="checkbox" name="enabled" value="1" ' . (!empty($hh['enabled']) ? 'checked' : '') . '> Show Happy Hour on the website</label>'
            . '<label class="check"><input type="checkbox" name="tickerEnabled" value="1" ' . (!empty($hh['tickerEnabled']) ? 'checked' : '') . '> Show the scrolling ticker</label>'
            . '<label class="field sm"><span>Starts</span><input type="time" name="start" value="' . h($hh['start']) . '"></label>'
            . '<label class="field sm"><span>Ends</span><input type="time" name="end" value="' . h($hh['end']) . '"></label>'
            . '<label class="field wide"><span>Intro line</span><input name="note" value="' . h($hh['note']) . '" maxlength="200"></label></div>')
        . card('Items', '<div class="table flush"><table class="tbl ftable"><thead><tr><th>Name</th><th>Description</th><th>Price</th></tr></thead><tbody>' . $itemRows . '</tbody></table></div>')
        . card('Sides', '<div class="table flush"><table class="tbl ftable"><thead><tr><th>Name</th><th>Price</th></tr></thead><tbody>' . $sideRows . '</tbody></table></div>')
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save Happy Hour', 'primary', '', '', 'check') . '</div></form>';
    admin_render('Happy Hour', $body, 'happy-hour');
}
