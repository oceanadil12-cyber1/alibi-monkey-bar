<?php
/** Activity log: who signed in and what they changed, newest first. */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_activity(): void
{
    $rows = '';
    foreach (admin_activity(200) as $a) {
        [$label, $tone] = admin_action_label((string) $a['action']);
        $ts = (int) strtotime((string) $a['time']);
        $rows .= '<tr><td class="nowrap">' . h(fmt_when($ts)) . '<div class="cell-sub">' . h(fmt_ago($ts)) . '</div></td>'
            . '<td>' . badge($label, $tone) . '</td>'
            . '<td>' . h($a['detail']) . '</td>'
            . '<td class="nowrap muted">' . h($a['ip']) . '</td></tr>';
    }
    $body = page_head('Activity log', 'Sign-ins and changes made in this admin, newest first (the latest 200).')
        . ($rows === ''
            ? empty_state('Nothing yet', 'Sign-ins and changes made here will appear in this list.', 'activity')
            : '<div class="table"><table class="tbl"><thead><tr><th>When</th><th>What</th><th>Details</th><th>From</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    admin_render('Activity log', $body, 'activity');
}
