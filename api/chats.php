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

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' | Alibi chat history</title><style>' . PAGE_CSS . '</style></head><body>'
        . '<header><strong>Alibi</strong> chat history' . (!empty($_SESSION['chats_ok']) ? ' <a class="out" href="?logout=1">Log out</a>' : '') . '</header>'
        . '<main>' . $body . '</main></body></html>';
    exit;
}

const PAGE_CSS = <<<'CSS'
:root { --bg: #0d0d0d; --card: #161616; --line: #2a2a2a; --text: #e8e8e8; --muted: #8f8f8f; --gold: #c8a84e; --gold-light: #e0c872; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
header { display: flex; align-items: center; gap: 8px; padding: 14px 20px; border-bottom: 1px solid var(--line); color: var(--muted); }
header strong { color: var(--gold); font-size: 17px; }
header .out { margin-left: auto; }
main { max-width: 980px; margin: 0 auto; padding: 20px 16px 60px; }
a { color: var(--gold-light); }
h1 { font-size: 22px; margin: 0 0 4px; }
.muted { color: var(--muted); font-size: 13px; }
.bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 16px 0; }
.bar a, .btn { padding: 6px 12px; border: 1px solid var(--line); border-radius: 999px; text-decoration: none; color: var(--text); background: var(--card); font-size: 13px; }
.bar a.on { border-color: var(--gold); color: var(--gold-light); }
.bar .dl { margin-left: auto; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 18px; }
.stat { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; }
.stat b { display: block; font-size: 22px; color: var(--gold-light); }
.table { overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; }
table { width: 100%; border-collapse: collapse; min-width: 640px; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; background: #121212; }
tr:last-child td { border-bottom: none; }
td.q { max-width: 380px; }
td.guest { font-size: 13px; line-height: 1.4; }
td.guest a { word-break: break-all; }
.who { margin: 0 0 4px; font-size: 14px; }
.who a { word-break: break-all; }
td.nowrap { white-space: nowrap; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 8px; }
.chip { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #1a1a1a; border: 1px solid var(--line); color: var(--muted); font-size: 12.5px; }
.chip.gold { background: #2a2210; border-color: rgba(200,168,78,.4); color: var(--gold-light); }
.ret { display: inline-block; margin-top: 3px; font-size: 11.5px; color: var(--gold-light); }
form.dates { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 18px; font-size: 13px; color: var(--muted); }
form.dates label { display: inline-flex; align-items: center; gap: 6px; }
form.dates input[type=date] { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--line); background: #0f0f0f; color: var(--text); font-size: 13px; color-scheme: dark; }
form.dates button { padding: 7px 14px; border: none; border-radius: 999px; background: var(--gold); color: #111; font-weight: 700; font-size: 13px; cursor: pointer; }
form.dates .clear { color: var(--muted); }
.prev { margin: 14px 0 4px; padding: 12px 14px; background: #17140b; border: 1px solid rgba(200,168,78,.35); border-radius: 12px; }
.prev strong { color: var(--gold-light); font-size: 13.5px; }
.prev ul { margin: 8px 0 0; padding-left: 2px; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.prev li { font-size: 13px; }
.prev li a { font-weight: 600; white-space: nowrap; }
.tag { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #2a2210; color: var(--gold-light); font-size: 12px; }
.chat { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
.msg { max-width: 78%; padding: 10px 14px; border-radius: 16px; white-space: pre-wrap; overflow-wrap: anywhere; }
.guest { align-self: flex-end; background: var(--gold); color: #111; border-bottom-right-radius: 5px; }
.bot { align-self: flex-start; background: var(--card); border: 1px solid var(--line); border-bottom-left-radius: 5px; }
.meta { font-size: 11.5px; color: var(--muted); margin-top: -4px; }
.meta.r { align-self: flex-end; }
.order { align-self: flex-start; border: 1px solid rgba(200, 168, 78, .5); border-radius: 12px; padding: 8px 12px; background: #1b170c; font-size: 13.5px; }
form.login { max-width: 340px; margin: 60px auto; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
form.login input { width: 100%; margin: 10px 0 14px; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: #0f0f0f; color: var(--text); font-size: 16px; }
form.login button { width: 100%; padding: 11px; border: none; border-radius: 10px; background: var(--gold); color: #111; font-weight: 700; font-size: 15px; cursor: pointer; }
.err { color: #f3a4a4; font-size: 13.5px; }
.empty { padding: 30px; text-align: center; color: var(--muted); }
.views { display: flex; gap: 4px; margin-bottom: 18px; border-bottom: 1px solid var(--line); }
.views a { padding: 8px 14px; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; font-weight: 600; }
.views a.on { color: var(--text); border-bottom-color: var(--gold); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; }
.panel { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px 16px 18px; min-width: 0; }
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

// Spreadsheet download.
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alibi-chats-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // lets Excel read accents and emoji
    fputcsv($out, ['conversation', 'name', 'email', 'ip', 'device', 'browser', 'distance (mi)', 'location', 'time', 'guest asked', 'assistant replied', 'added to order', 'AI', 'input tokens', 'output tokens', 'seconds']);
    foreach (array_reverse(chat_log_conversations($days, $from, $to)) as $c) {
        $d = !empty($c['ip']) ? geo_distance($c['ip']) : null;
        $miles = $d ? round($d['miles']) : '';
        $place = $d ? $d['place'] : '';
        foreach ($c['turns'] as $t) {
            $row = [$c['cid'], $c['name'] ?? '', $c['email'] ?? '', $c['ip'] ?? '', $c['device'] ?? '', $c['browser'] ?? '', $miles, $place, $t['time'], $t['question'], $t['reply'], implode('; ', $t['ordered'] ?? []), trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? '')), $t['input_tokens'] ?? 0, $t['output_tokens'] ?? 0, $t['seconds'] ?? 0];
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
    . '<a href="?' . $period . '&amp;view=insights"' . ($view === 'insights' ? ' class="on"' : '') . '>Insights</a></nav>';

if ($view === 'insights') {
    require_once __DIR__ . '/chat-insights.php';
    page('Insights', $nav . '<h1>Insights</h1><p class="muted">What guests ask about and order in the chat. Counted from their own words, so treat it as a guide rather than exact figures. Full sales are in Square.</p>'
        . '<div class="bar">' . $tabs . '</div>' . $dateForm . insights_html(chat_insights($convos), $days));
}

$html = $nav . '<h1>Conversations</h1><p class="muted">Guests give an email (and optionally a name) before chatting; their IP address, device and browser are recorded too. Kept for ' . (int) config()['chat_log_days'] . ' days, then deleted automatically.</p>'
    . '<div class="bar">' . $tabs . '<a class="dl" href="?csv=1&amp;' . $period . '">Download spreadsheet</a></div>'
    . $dateForm
    . '<div class="stats"><div class="stat"><b>' . count($convos) . '</b>conversations</div><div class="stat"><b>' . $questions . '</b>questions</div>'
    . '<div class="stat"><b>' . $withOrders . '</b>with food ordered</div><div class="stat"><b>' . number_format($tokens) . '</b>AI tokens used</div></div>';

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
    $guest = $email !== ''
        ? ($name !== '' ? '<b>' . h($name) . '</b><br>' : '') . '<a href="mailto:' . h($email) . '">' . h($email) . '</a>'
            . ($ecount > 1 ? ' <span class="ret" title="' . $ecount . ' chats from this email in view">&#8617; returning</span>' : '')
        : '<span class="muted">&ndash;</span>';
    $device = (string) ($c['device'] ?? '');
    $browser = (string) ($c['browser'] ?? '');
    $devCell = $device !== '' ? h($device) . ($browser !== '' ? '<br><span class="muted">' . h($browser) . '</span>' : '') : '<span class="muted">&ndash;</span>';
    $d = !empty($c['ip']) ? geo_distance($c['ip']) : null;
    $distCell = $d ? '~' . number_format($d['miles'], $d['miles'] < 10 ? 1 : 0) . ' mi' . ($d['place'] !== '' ? '<br><span class="muted">' . h($d['place']) . '</span>' : '') : '<span class="muted">&ndash;</span>';
    $html .= '<tr><td class="nowrap">' . h($when($c['end'])) . '</td><td class="guest">' . $guest . '</td>'
        . '<td>' . $devCell . '</td><td>' . $distCell . '</td><td>' . count($c['turns']) . '</td>'
        . '<td>' . ($items ? '<span class="tag">' . $items . ' item' . ($items === 1 ? '' : 's') . '</span>' : '&ndash;') . '</td>'
        . '<td class="q">' . h(mb_strimwidth($first, 0, 90, '…')) . '</td>'
        . '<td><a href="?c=' . rawurlencode($c['cid']) . '&amp;' . $period . '">Read &rarr;</a></td></tr>';
}
page('Conversations', $html . '</tbody></table></div>');
