<?php
/**
 * Activity log: who signed in and what they changed, newest first, plus the
 * email alert settings (every change logged here can also be emailed; see
 * change_alert() in admin/inc/store.php).
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** Save the "Email alerts" form; returns an error message, or '' once saved. */
function save_alert_settings(): string
{
    $email = trim(mb_substr((string) ($_POST['alert_email'] ?? ''), 0, 120));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'That email address doesn\'t look right. Nothing was changed.';
    $old = alert_settings();
    $new = [
        'enabled' => !empty($_POST['alert_enabled']),
        'email'   => $email,
        'signins' => !empty($_POST['alert_signins']),
        'kitchen' => !empty($_POST['alert_kitchen']),
    ];
    db_setting_save('change_alerts', $new);
    $changes = [];
    if ($old['enabled'] !== $new['enabled']) $changes[] = 'Email alerts ' . ($new['enabled'] ? 'switched on' : 'switched off');
    if ($old['email'] !== $new['email']) $changes[] = 'Alerts go to ' . ($new['email'] !== '' ? $new['email'] : 'the restaurant\'s notification address');
    if ($old['signins'] !== $new['signins']) $changes[] = 'Sign-in alerts ' . ($new['signins'] ? 'on' : 'off');
    if ($old['kitchen'] !== $new['kitchen']) $changes[] = 'Kitchen & waiter alerts ' . ($new['kitchen'] ? 'on' : 'off');
    if ($changes) {
        admin_log('alerts.settings', '', $changes);
        // Switching alerts off is still emailed once, to the old address, so it never happens silently.
        if (!$new['enabled'] && $old['enabled']) change_alert('alerts.settings', '', $changes, $old);
    }
    return '';
}

function admin_page_activity(): void
{
    if (is_post()) {
        require_csrf();
        $op = (string) ($_POST['op'] ?? '');
        if ($op === 'alerts') {
            $error = save_alert_settings();
            flash($error !== '' ? $error : 'Saved. Email alerts use the new settings from now on.', $error !== '' ? 'err' : 'ok');
        } elseif ($op === 'alert-test') {
            $s = alert_settings();
            $to = alert_recipient($s);
            if ($to === '') {
                flash('There\'s no address to send to. Enter one below and save, or set notify_email in config.php.', 'err');
            } elseif (change_alert('alerts.test', 'This is a test. Change alerts from the Alibi admin will arrive like this.', [], $s)) {
                flash('Test alert sent to ' . $to . '. If it doesn\'t arrive in a few minutes, check the spam folder.');
            } else {
                flash('The test alert couldn\'t be sent: this server\'s email isn\'t working. Ask your web host to check PHP mail.', 'err');
            }
        }
        redirect(admin_url('activity'));
    }

    $s = alert_settings();
    $fallback = trim((string) (config()['notify_email'] ?? ''));
    $to = alert_recipient($s);
    $alerts = card('Email alerts',
        form_open(admin_url('activity'))
        . '<div class="form-grid">'
        . '<label class="check"><input type="checkbox" name="alert_enabled" value="1" ' . (!empty($s['enabled']) ? 'checked' : '') . '> Email me when something is changed</label>'
        . '<label class="field"><span>Send alerts to</span><input type="email" name="alert_email" value="' . h($s['email']) . '" maxlength="120" placeholder="' . h($fallback !== '' ? $fallback : 'name@example.com') . '"></label>'
        . '<label class="check"><input type="checkbox" name="alert_signins" value="1" ' . (!empty($s['signins']) ? 'checked' : '') . '> Also email sign-ins, sign-outs and wrong passwords</label>'
        . '<label class="check"><input type="checkbox" name="alert_kitchen" value="1" ' . (!empty($s['kitchen']) ? 'checked' : '') . '> Also email kitchen display and waiter actions</label>'
        . '</div>'
        . '<p class="muted">Menu, prices, Happy Hour, reservations, orders, messages, events, performers, settings and password changes are emailed as they happen, each listing exactly what changed. '
        . ($to !== '' ? 'Alerts currently go to <b>' . h($to) . '</b>.' : 'No address is set yet, so nothing is sent.') . ' Leave the address blank to use the restaurant\'s notification email.</p>'
        // Save first: pressing Enter in the address box uses the first button.
        . '<div class="bar">' . btn_submit('Save alert settings', 'primary', 'op', 'alerts', 'check') . btn_submit('Send a test alert', '', 'op', 'alert-test', 'mail') . '</div></form>');

    $rows = '';
    foreach (admin_activity(200) as $a) {
        [$label, $tone] = admin_action_label((string) $a['action']);
        $ts = (int) strtotime((string) $a['time']);
        $rows .= '<tr><td class="nowrap">' . h(fmt_when($ts)) . '<div class="cell-sub">' . h(fmt_ago($ts)) . '</div></td>'
            . '<td>' . badge($label, $tone) . '</td>'
            . '<td class="q">' . h($a['detail']) . '</td>'
            . '<td class="nowrap muted">' . h($a['ip']) . '</td></tr>';
    }
    $body = page_head('Activity log', 'Sign-ins and changes made in this admin and the kitchen display, newest first (the latest 200).')
        . $alerts
        . ($rows === ''
            ? empty_state('Nothing yet', 'Sign-ins and changes made here will appear in this list.', 'activity')
            : '<div class="table"><table class="tbl"><thead><tr><th>When</th><th>What</th><th>Details</th><th>From</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    admin_render('Activity log', $body, 'activity');
}
