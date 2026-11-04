<?php
/**
 * Team accounts: one username and password per person, so the activity log shows
 * who did what. Only the owner and Admin accounts see this page. The owner account
 * ("admin" + the team password) isn't listed: it lives in config.php / Change password.
 * Account rules are in api/team-users.php.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_users(): void
{
    if (!admin_is_admin()) {
        admin_render('Team accounts', page_head('Team accounts') . empty_state('Only admins can manage team accounts', 'Ask the owner or an Admin account to add or change accounts.', 'lock'), 'users');
    }
    if (is_post()) admin_users_post();
    $id = get_str('id', 10);
    if (ctype_digit($id)) admin_user_detail((int) $id); // ends the request
    admin_users_list();
}

function team_role_badge(string $role): string
{
    return badge(TEAM_ROLES[$role][0] ?? $role, ['admin' => 'gold', 'staff' => 'info', 'kitchen' => 'warn'][$role] ?? 'muted');
}

/** The role picker, with what each role can open. */
function team_role_select(string $current): string
{
    $out = '<select name="role">';
    foreach (TEAM_ROLES as $key => [$label, $what]) $out .= '<option value="' . h($key) . '"' . ($key === $current ? ' selected' : '') . '>' . h("$label: $what") . '</option>';
    return $out . '</select>';
}

/** A new password from the form: [password, ''] or ['', reason]. */
function team_password_from_post(): array
{
    $new = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if (mb_strlen($new) < TEAM_USER_PASSWORD_MIN) return ['', 'The password needs at least ' . TEAM_USER_PASSWORD_MIN . ' characters.'];
    if (strlen($new) > 72) return ['', 'The password can be at most 72 characters.']; // bcrypt's limit
    if ($new !== $confirm) return ['', 'The two passwords don\'t match.'];
    return [$new, ''];
}

function admin_users_post(): void
{
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $me = team_actor();

    if ($action === 'add') {
        $back = admin_url('users');
        $username = team_clean_username(post_str('username', 40));
        $name = post_str('name', 80);
        $role = isset(TEAM_ROLES[$_POST['role'] ?? '']) ? (string) $_POST['role'] : 'staff';
        if ($username === '') { flash('Choose a username of 3 to 30 letters or numbers (dots, dashes and underscores are fine too).', 'err'); redirect($back); }
        if ($username === TEAM_OWNER) { flash('"admin" is the owner\'s username. Choose another.', 'err'); redirect($back); }
        if (team_user_find('username', $username)) { flash("There's already an account called $username.", 'err'); redirect($back); }
        if ($name === '') { flash('Enter the person\'s name.', 'err'); redirect($back); }
        [$password, $err] = team_password_from_post();
        if ($err !== '') { flash($err, 'err'); redirect($back); }
        $id = db_insert('admin_users', [
            'username' => $username, 'name' => $name, 'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_by' => $me,
        ]);
        admin_log('user.add', "$username ($name), " . TEAM_ROLES[$role][0]);
        flash("Account $username added. Give $name their username and password; they can change the password after signing in.");
        redirect(admin_url('users', ['id' => $id]));
    }

    $u = ctype_digit((string) ($_POST['id'] ?? '')) ? team_user_find('id', (int) $_POST['id']) : null;
    if (!$u) {
        flash('That account wasn\'t found.', 'err');
        redirect(admin_url('users'));
    }
    $id = (int) $u['id'];
    $back = admin_url('users', ['id' => $id]);
    $self = $u['username'] === $me;

    switch ($action) {
        case 'edit':
            $name = post_str('name', 80);
            $role = isset(TEAM_ROLES[$_POST['role'] ?? '']) ? (string) $_POST['role'] : $u['role'];
            if ($name === '') { flash('Enter the person\'s name.', 'err'); break; }
            if ($self && $role !== 'admin') { flash('You can\'t take away your own Admin role. Ask another admin, or sign in as the owner.', 'err'); break; }
            $changes = [];
            if ($name !== $u['name']) $changes[] = "name $u[name] → $name";
            if ($role !== $u['role']) $changes[] = 'role ' . TEAM_ROLES[$u['role']][0] . ' → ' . TEAM_ROLES[$role][0];
            if (!$changes) { flash('Nothing changed.'); break; }
            db_update('admin_users', ['name' => $name, 'role' => $role], ['id' => $id]);
            admin_log('user.edit', $u['username'], $changes);
            flash('Saved.' . ($role !== $u['role'] ? ' ' . $name . ' needs to sign in again.' : ''));
            break;

        case 'password':
            [$password, $err] = team_password_from_post();
            if ($err !== '') { flash($err, 'err'); break; }
            db_update('admin_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
            admin_log('user.password', $u['username']);
            if ($self) { // stay signed in here
                $_SESSION['admin_pw'] = team_user_version(team_user_find('id', $id));
                flash('Your password was changed.');
            } else {
                flash('Password reset. ' . $u['name'] . ' is signed out everywhere and uses the new password from now on.');
            }
            break;

        case 'toggle':
            if ($self) { flash('You can\'t switch off your own account.', 'err'); break; }
            $on = !(int) $u['active'];
            db_update('admin_users', ['active' => $on ? 1 : 0], ['id' => $id]);
            admin_log($on ? 'user.enable' : 'user.disable', $u['username'] . " ($u[name])");
            flash($on ? 'Account switched on. ' . $u['name'] . ' can sign in again.' : 'Account switched off. ' . $u['name'] . ' is signed out and can\'t sign in.');
            break;

        case 'delete':
            if ($self) { flash('You can\'t delete your own account.', 'err'); break; }
            db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
            admin_log('user.delete', $u['username'] . " ($u[name])");
            flash('Account ' . $u['username'] . ' deleted. Their past activity stays in the log under their username.');
            redirect(admin_url('users'));
    }
    redirect($back);
}

function admin_user_detail(int $id): void
{
    $u = team_user_find('id', $id);
    $back = '<p class="back"><a href="' . h(admin_url('users')) . '">' . icon('left', 15) . ' Team accounts</a></p>';
    if (!$u) admin_render('Team account', $back . empty_state('Account not found', '', 'users'), 'users');
    $self = $u['username'] === team_actor();
    $hidden = '<input type="hidden" name="id" value="' . $id . '">';
    $form = fn(string $action, string $inner) => form_open(admin_url('users')) . $hidden . '<input type="hidden" name="action" value="' . h($action) . '">' . $inner . '</form>';
    $min = TEAM_USER_PASSWORD_MIN;

    $recent = '';
    foreach (admin_activity(15, $u['username']) as $a) {
        [$label, $tone] = admin_action_label((string) $a['action']);
        $recent .= '<tr><td class="nowrap">' . h(fmt_when((string) $a['time'])) . '</td><td>' . badge($label, $tone) . '</td><td class="q">' . h($a['detail']) . '</td></tr>';
    }

    $body = $back
        . page_head($u['name'] !== '' ? $u['name'] : $u['username'], 'Username <b>' . h($u['username']) . '</b>' . ($self ? ' &middot; this is you' : ''),
            team_role_badge($u['role']) . ' ' . ((int) $u['active'] ? badge('On', 'good') : badge('Switched off', 'crit')))
        . '<div class="split"><div class="stack">'
        .   card('Details', $form('edit', '<div class="form-grid">'
                . '<label class="field"><span>Name</span><input name="name" maxlength="80" required value="' . h($u['name']) . '"></label>'
                . '<label class="field"><span>Role' . info_tip('Admin: everything, including these accounts. Staff: the admin panel and the kitchen display. Kitchen: the kitchen display only.') . '</span>' . team_role_select($u['role']) . '</label>'
                . '</div><div class="bar">' . btn_submit('Save', 'primary') . '</div>'))
        .   card($self ? 'Change your password' : 'Reset password', $form('password',
                '<input type="text" name="username" value="' . h($u['username']) . '" autocomplete="username" hidden>'
                . '<div class="form-grid">'
                . '<label class="field"><span>New password' . info_tip("At least $min characters. A few unrelated words make a strong, memorable password.") . '</span><input type="password" name="password" autocomplete="new-password" minlength="' . $min . '" maxlength="72" required></label>'
                . '<label class="field"><span>Confirm</span><input type="password" name="confirm" autocomplete="new-password" minlength="' . $min . '" maxlength="72" required></label>'
                . '</div><p class="muted small">' . ($self ? 'You stay signed in here.' : 'This signs ' . h($u['name']) . ' out everywhere.') . '</p>'
                . '<div class="bar">' . btn_submit($self ? 'Change password' : 'Reset password', '', '', '', 'lock') . '</div>'))
        .   card('Recent activity', $recent === '' ? '<p class="empty-sm">Nothing logged yet.</p>'
                : '<div class="table"><table class="tbl"><thead><tr><th>When</th><th>What</th><th>Details</th></tr></thead><tbody>' . $recent . '</tbody></table></div>'
                . '<div class="bar">' . btn_link('All their activity', admin_url('activity', ['user' => $u['username']]), 'sm', 'activity') . '</div>')
        . '</div><div class="stack">'
        .   card('Account', kv([
                ['Last sign-in', $u['last_login'] ? h(fmt_when($u['last_login'])) : '<span class="muted">Never</span>'],
                ['Added', h(fmt_when($u['created_at'])) . ($u['created_by'] !== '' ? ' by ' . h($u['created_by']) : '')],
            ]) . ($self ? '<p class="muted small">You can\'t switch off or delete your own account.</p>'
                : '<div class="btns">'
                . $form('toggle', btn_submit((int) $u['active'] ? 'Switch off' : 'Switch on', 'sm', '', '', (int) $u['active'] ? 'x' : 'check',
                    (int) $u['active'] ? 'Switch off ' . $u['name'] . '\'s account? They\'re signed out and can\'t sign in until it\'s switched back on.' : ''))
                . $form('delete', btn_submit('Delete', 'sm ghost', '', '', 'trash', 'Delete ' . $u['username'] . '? Their past activity stays in the log.'))
                . '</div>'))
        . '</div></div>';
    admin_render('Team account', $body, 'users');
}

function admin_users_list(): void
{
    $rows = '';
    foreach (team_users_all() as $u) {
        $rows .= '<tr><td><a class="row-link" href="' . h(admin_url('users', ['id' => $u['id']])) . '">' . guest_cell($u['name'], $u['username']) . '</a></td>'
            . '<td>' . team_role_badge($u['role']) . '</td>'
            . '<td>' . ((int) $u['active'] ? badge('On', 'good') : badge('Switched off', 'crit')) . '</td>'
            . '<td class="nowrap"><span class="cell-sub">' . ($u['last_login'] ? h(fmt_when($u['last_login'])) : 'Never') . '</span></td></tr>';
    }
    $owner = '<tr><td>' . guest_cell('Owner', TEAM_OWNER, '<span class="cell-sub">Signs in with the team password</span>') . '</td><td>' . team_role_badge('admin') . '</td><td>' . badge('Always on', 'good') . '</td>'
        . '<td><a class="cell-sub" href="' . h(admin_url('password')) . '">Change password</a></td></tr>';
    $min = TEAM_USER_PASSWORD_MIN;

    $add = form_open(admin_url('users')) . '<input type="hidden" name="action" value="add">'
        . '<div class="form-grid">'
        . '<label class="field"><span>Name</span><input name="name" maxlength="80" required placeholder="Maria Gomez"></label>'
        . '<label class="field"><span>Username' . info_tip('What they type to sign in: 3 to 30 letters or numbers, with dots, dashes or underscores if you like. Not case-sensitive.') . '</span>'
        .   '<input name="username" maxlength="30" required autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="maria"></label>'
        . '<label class="field wide"><span>Role' . info_tip('Admin: everything, including these accounts. Staff: the admin panel and the kitchen display. Kitchen: the kitchen display only.') . '</span>' . team_role_select('staff') . '</label>'
        . '<label class="field"><span>Password' . info_tip("At least $min characters. They can change it after signing in.") . '</span><input type="password" name="password" autocomplete="new-password" minlength="' . $min . '" maxlength="72" required></label>'
        . '<label class="field"><span>Confirm password</span><input type="password" name="confirm" autocomplete="new-password" minlength="' . $min . '" maxlength="72" required></label>'
        . '</div><div class="bar">' . btn_submit('Add account', 'primary', '', '', 'plus') . '</div></form>';

    $body = page_head('Team accounts', 'Give each person their own username and password. The activity log then shows who did what.')
        . '<div class="split wideleft"><div class="stack">'
        . '<div class="table"><table class="tbl"><thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Last sign-in</th></tr></thead><tbody>' . $owner . $rows . '</tbody></table></div>'
        . ($rows === '' ? '<p class="muted small">No team accounts yet. Until you add some, everyone signs in as <b>admin</b> with the team password.</p>' : '')
        . '</div>' . card('Add an account', $add) . '</div>';
    admin_render('Team accounts', $body, 'users');
}
