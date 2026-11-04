<?php
/**
 * Table QR codes: a printable card for each table. Guests scan it to open the table
 * ordering page for that table (dine-in/table/N, see dine-in/index.php), order, or
 * call a waiter. The codes are made here (admin/inc/qr.php), with no outside service.
 *
 *   ?page=table-qr&from=1&to=20&size=4    cards for tables 1–20, four to a page
 *   ?page=table-qr&svg=5                  table 5's code as an SVG file, for a print shop
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../qr.php';

const TABLE_QR_MAX = 999;    // the table ordering page takes tables 1–999
const TABLE_QR_PER_PRINT = 100;

/** The address the codes point at: the saved one, else this site's own (https://host/folder). */
function table_qr_site(): string
{
    $saved = (string) db_setting('table-qr-site', '');
    if ($saved !== '') return $saved;
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . rtrim(SITE_BASE, '/');
}

/** "https://alibiwiltonmanors.com/" or "alibiwiltonmanors.com" → "https://alibiwiltonmanors.com"; '' if it isn't a web address. */
function table_qr_clean_site(string $s): string
{
    $s = trim($s);
    if ($s !== '' && !preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
    $s = rtrim($s, '/');
    $p = parse_url($s);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['query']) || isset($p['fragment'])) return '';
    return $s;
}

function table_qr_link(string $site, int $table): string
{
    return $site . '/dine-in/table/' . $table;
}

/** True for an address only this computer or network can open (localhost, 127.x, 192.168.x…). */
function table_qr_is_local(string $site): bool
{
    $host = strtolower((string) parse_url($site, PHP_URL_HOST));
    return $host === 'localhost' || preg_match('/\.(localhost|test|local)$/', $host)
        || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
}

function admin_page_table_qr(): void
{
    if (is_post()) {
        require_csrf();
        $site = table_qr_clean_site(post_str('site', 200));
        if ($site === '') {
            flash('That website address doesn\'t look right. Use one like https://alibiwiltonmanors.com', 'err');
        } else {
            db_setting_save('table-qr-site', $site);
            admin_log('settings.save', "Table QR codes point to $site");
            flash("Saved. The codes now point to $site.");
        }
        redirect(admin_url('table-qr'));
    }

    $site = table_qr_site();
    $svg = get_str('svg', 4);
    if (ctype_digit($svg) && (int) $svg >= 1 && (int) $svg <= TABLE_QR_MAX) {
        header('Content-Type: image/svg+xml');
        header('Content-Disposition: attachment; filename="alibi-table-' . (int) $svg . '-qr.svg"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . qr_svg(table_qr_link($site, (int) $svg));
        exit;
    }

    $from = max(1, min(TABLE_QR_MAX, (int) ($_GET['from'] ?? 1)));
    $to = max($from, min(TABLE_QR_MAX, (int) ($_GET['to'] ?? 20)));
    $capped = $to - $from + 1 > TABLE_QR_PER_PRINT;
    if ($capped) $to = $from + TABLE_QR_PER_PRINT - 1;
    $size = get_choice('size', ['4', '1'], '4');

    $cards = '';
    for ($t = $from; $t <= $to; $t++) {
        $link = table_qr_link($site, $t);
        $cards .= '<article class="qr-card">'
            . '<img class="qr-logo" src="' . h(SITE_BASE) . 'images/logo.webp" alt="Georgie\'s Alibi Monkey Bar">'
            . '<p class="qr-table"><span>Table</span><b>' . $t . '</b></p>'
            . '<div class="qr-code">' . qr_svg($link, "QR code for table $t") . '</div>'
            . '<p class="qr-cta">Scan to order</p>'
            . '<p class="qr-help">Point your phone camera at the code. Order here or call your server.</p>'
            . '<p class="qr-url">' . h(preg_replace('#^https?://(www\.)?#', '', $link)) . '</p>'
            . '<p class="qr-actions no-print">'
            .   '<a href="' . h($link) . '" target="_blank" rel="noopener">' . icon('external', 13) . ' Open</a>'
            .   '<a href="' . h(admin_url('table-qr', ['svg' => $t])) . '" download>' . icon('download', 13) . ' SVG</a>'
            . '</p></article>';
    }

    $local = table_qr_is_local($site);
    $controls = '<section class="card no-print"><div class="card-body qr-controls">'
        . '<form method="get" class="qr-form"><input type="hidden" name="page" value="table-qr">'
        . '<label class="field sm"><span>From table</span><input type="number" name="from" min="1" max="' . TABLE_QR_MAX . '" value="' . $from . '" inputmode="numeric"></label>'
        . '<label class="field sm"><span>To table</span><input type="number" name="to" min="1" max="' . TABLE_QR_MAX . '" value="' . $to . '" inputmode="numeric"></label>'
        . '<label class="field"><span>Card size' . info_tip('Standard fits four cards on a page, for stickers or small stands. Large is one card per page, for table tents.') . '</span>'
        .   '<select name="size"><option value="4"' . ($size === '4' ? ' selected' : '') . '>Standard: 4 per page</option><option value="1"' . ($size === '1' ? ' selected' : '') . '>Large: 1 per page</option></select></label>'
        . '<div class="field sm"><span>&nbsp;</span>' . btn_submit('Show cards', '', '', '', 'refresh') . '</div>'
        . '</form>'
        . form_open(admin_url('table-qr'), 'qr-form')
        . '<label class="field"><span>Website address' . info_tip('The address the codes open, without "/dine-in". It\'s normally this website\'s own address; change it only if the site moves to a new domain.') . '</span>'
        .   '<input name="site" value="' . h($site) . '" maxlength="200" inputmode="url" autocapitalize="none" spellcheck="false"></label>'
        . '<div class="field sm"><span>&nbsp;</span>' . btn_submit('Save address', '', '', '', 'check') . '</div>'
        . '</form>'
        . ($local ? '<div class="callout warn">' . icon('alert', 16) . '<span><b>These codes point to ' . h($site) . ', which only this computer can open.</b> Before printing, enter the live address (for example https://alibiwiltonmanors.com) and save it.</span></div>' : '')
        . ($capped ? '<p class="muted small">Showing ' . TABLE_QR_PER_PRINT . ' cards at a time: print these, then show the next tables.</p>' : '')
        . '</div></section>';

    $body = page_head('Table QR codes', 'Guests scan the code on their table to order from their phone or call a server. Orders arrive on the kitchen display and in Orders as dine-in, with the table number.',
            '<button class="btn primary" type="button" data-print>' . icon('print', 16) . '<span>Print ' . plural($to - $from + 1, 'card') . '</span></button>')
        . $controls
        . '<div class="qr-sheet size-' . $size . '">' . $cards . '</div>'
        . '<p class="muted small no-print qr-tip">Tip: test one card with your own phone before printing them all. Laminated cards or table stands last longest; keep each code at least 3 cm (1.2 in) wide.</p>';
    admin_render('Table QR codes', $body, 'table-qr');
}
