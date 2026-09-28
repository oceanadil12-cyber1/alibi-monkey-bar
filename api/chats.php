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
CSS;

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

// Spreadsheet download.
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alibi-chats-' . date('Y-m-d') . "-last-{$days}-days.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // lets Excel read accents and emoji
    fputcsv($out, ['conversation', 'time', 'guest asked', 'assistant replied', 'added to order', 'AI', 'input tokens', 'output tokens', 'seconds']);
    foreach (array_reverse(chat_log_conversations($days)) as $c) {
        foreach ($c['turns'] as $t) {
            $row = [$c['cid'], $t['time'], $t['question'], $t['reply'], implode('; ', $t['ordered'] ?? []), trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? '')), $t['input_tokens'] ?? 0, $t['output_tokens'] ?? 0, $t['seconds'] ?? 0];
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
    $c = chat_log_conversations(120)[$cid] ?? null;
    if (!$c) page('Not found', '<p><a href="chats.php?days=' . $days . '">&larr; All conversations</a></p><p class="empty">This conversation was not found (chats are kept for ' . (int) config()['chat_log_days'] . ' days).</p>');
    $ordered = array_merge(...array_map(fn($t) => $t['ordered'] ?? [], $c['turns']));
    $html = '<p><a href="chats.php?days=' . $days . '">&larr; All conversations</a></p>'
        . '<h1>Conversation ' . h($cid) . '</h1><p class="muted">' . h($when($c['start'])) . ' &ndash; ' . h($when($c['end'])) . ' &middot; '
        . count($c['turns']) . ' question' . (count($c['turns']) === 1 ? '' : 's') . ($ordered ? ' &middot; <span class="tag">ordered ' . count($ordered) . ' item' . (count($ordered) === 1 ? '' : 's') . '</span>' : '') . '</p><div class="chat">';
    foreach ($c['turns'] as $t) {
        $html .= '<div class="msg guest">' . h($t['question']) . '</div><div class="meta r">Guest &middot; ' . h($when($t['time'])) . '</div>'
            // Markdown asterisks are removed as in the chat window (js/chat.js).
            . '<div class="msg bot">' . h(preg_replace(['/\*\*(.+?)\*\*/s', '/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/m'], ['$1', '$1$2'], $t['reply'])) . '</div>'
            . '<div class="meta">' . h(trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? ''))) . ' &middot; ' . h($t['seconds'] ?? '') . 's</div>';
        if (!empty($t['ordered'])) $html .= '<div class="order">&#128722; Added to order: ' . h(implode(', ', $t['ordered'])) . '</div>';
    }
    page('Conversation', $html . '</div>');
}

// The list.
$convos = chat_log_conversations($days);
$questions = array_sum(array_map(fn($c) => count($c['turns']), $convos));
$withOrders = count(array_filter($convos, fn($c) => array_filter($c['turns'], fn($t) => !empty($t['ordered']))));
$tokens = array_sum(array_map(fn($c) => array_sum(array_map(fn($t) => ($t['input_tokens'] ?? 0) + ($t['output_tokens'] ?? 0), $c['turns'])), $convos));

$tabs = '';
foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 120 => '120 days'] as $d => $label) {
    $tabs .= '<a href="?days=' . $d . '"' . ($d === $days ? ' class="on"' : '') . '>' . $label . '</a>';
}
$html = '<h1>Conversations</h1><p class="muted">Anonymous: no names, emails or IP addresses. Kept for ' . (int) config()['chat_log_days'] . ' days, then deleted automatically.</p>'
    . '<div class="bar">' . $tabs . '<a class="dl" href="?csv=1&amp;days=' . $days . '">Download spreadsheet</a></div>'
    . '<div class="stats"><div class="stat"><b>' . count($convos) . '</b>conversations</div><div class="stat"><b>' . $questions . '</b>questions</div>'
    . '<div class="stat"><b>' . $withOrders . '</b>with food ordered</div><div class="stat"><b>' . number_format($tokens) . '</b>AI tokens used</div></div>';

if (!$convos) {
    page('Conversations', $html . '<p class="empty">No chats in this period yet.</p>');
}
$html .= '<div class="table"><table><thead><tr><th>Last message</th><th>Questions</th><th>Ordered</th><th>First question</th><th></th></tr></thead><tbody>';
foreach ($convos as $c) {
    $items = count(array_merge(...array_map(fn($t) => $t['ordered'] ?? [], $c['turns'])));
    $first = $c['turns'][0]['question'] ?? '';
    $html .= '<tr><td>' . h($when($c['end'])) . '</td><td>' . count($c['turns']) . '</td>'
        . '<td>' . ($items ? '<span class="tag">' . $items . ' item' . ($items === 1 ? '' : 's') . '</span>' : '&ndash;') . '</td>'
        . '<td class="q">' . h(mb_strimwidth($first, 0, 110, '…')) . '</td>'
        . '<td><a href="?c=' . rawurlencode($c['cid']) . '&amp;days=' . $days . '">Read &rarr;</a></td></tr>';
}
page('Conversations', $html . '</tbody></table></div>');
