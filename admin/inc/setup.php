<?php
/**
 * First run: connect the website to its MySQL database from the browser.
 *
 * Shown to a signed-in admin while the database isn't set up yet, or can't be
 * reached with the saved details. It tests what was typed, creates the tables
 * from api/setup.sql and saves the details in api/config.php, so nobody has to
 * edit files on the server. Everything else (admin password, emails, chat keys)
 * arrives with the upload package in api/site-settings.php.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** The setup screen, or the redirect to the dashboard once the database works. Always ends the request. */
function admin_setup_database(): void
{
    $c = config();
    $v = [
        'db_name' => (string) ($c['db_name'] ?? ''),
        'db_user' => (string) ($c['db_user'] ?? ''),
        'db_host' => (string) ($c['db_host'] ?? '') ?: 'localhost',
        'db_port' => (int) ($c['db_port'] ?? 3306) ?: 3306,
    ];
    $error = '';
    if (!db_configured()) {
        $intro = 'One last step: connect the website to its MySQL database. Create the database and a user in '
            . '<b>cPanel → MySQL Databases</b> (add the user to the database with <b>All Privileges</b>), then enter them here. '
            . 'The tables are created for you.';
    } else {
        $intro = 'The database didn\'t answer with the saved details. If MySQL is only down for a moment, '
            . '<a href="' . h(ADMIN_BASE) . '">try again</a>; otherwise enter the right details below.';
    }

    if (is_post() && isset($_POST['db_name'])) {
        require_csrf();
        foreach (['db_name', 'db_user', 'db_host'] as $k) $v[$k] = trim((string) ($_POST[$k] ?? ''));
        $v['db_port'] = (int) ($_POST['db_port'] ?? 3306) ?: 3306;
        if ($v['db_host'] === '') $v['db_host'] = 'localhost';
        $pass = (string) ($_POST['db_pass'] ?? '');

        if ($v['db_name'] === '' || $v['db_user'] === '') {
            $error = 'Enter the database name and the database user.';
        } else {
            try {
                $pdo = db_connect($v['db_host'], $v['db_port'], $v['db_name'], $v['db_user'], $pass);
                db_ensure_schema($pdo, true);
                $missing = db_missing_tables($pdo);
                if ($missing) {
                    $error = 'Connected, but the tables couldn\'t be created: the user needs All Privileges on the database '
                        . '(cPanel → MySQL Databases → Add User To Database). Missing: ' . implode(', ', $missing) . '.';
                } elseif (!db_save_config($v + ['db_pass' => $pass])) {
                    $error = 'Connected, but the details couldn\'t be saved: the folder api/ must be writable by the website '
                        . '(File Manager → api → Permissions 755).';
                } else {
                    // Logged through this connection: db() still holds this request's old settings.
                    $pdo->prepare('INSERT INTO admin_activity (action, detail, ip) VALUES (?, ?, ?)')
                        ->execute(['setup.database', $v['db_name'], client_ip()]);
                    flash('Database connected. Everything is ready.');
                    redirect(ADMIN_BASE);
                }
            } catch (PDOException $e) {
                error_log('admin setup: ' . $e->getMessage());
                $error = setup_db_problem($e);
            }
        }
    }

    $field = function (string $label, string $name, string $value, string $type = 'text', string $extra = '') {
        return '<label class="field"><span>' . h($label) . '</span><input type="' . $type . '" name="' . $name . '" value="' . h($value) . '"' . $extra . '></label>';
    };
    admin_render_bare('Connect the database',
        '<form class="login setup" method="post">'
        . '<div class="login-logo"><img src="' . h(SITE_BASE) . 'images/logo.webp" alt="Georgie\'s Alibi Monkey Bar" height="56"></div>'
        . '<h1>Connect the database</h1>'
        . '<p class="muted">' . $intro . '</p>'
        . ($error !== '' ? '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($error) . '</span></div>' : '')
        . csrf_field()
        . $field('Database name', 'db_name', $v['db_name'], 'text', ' placeholder="e.g. cpuser_alibi" autocomplete="off" required autofocus')
        . $field('Database user', 'db_user', $v['db_user'], 'text', ' placeholder="e.g. cpuser_alibi" autocomplete="off" required')
        . $field('Database password', 'db_pass', '', 'password', ' autocomplete="new-password"')
        . '<details class="setup-more"><summary>Server address (only if your host gave you one)</summary>'
        .   $field('Host', 'db_host', $v['db_host'], 'text', ' autocomplete="off"')
        .   $field('Port', 'db_port', (string) $v['db_port'], 'number', ' min="1" max="65535"')
        . '</details>'
        . '<button class="btn primary block" type="submit">' . icon('check', 16) . '<span>Connect and finish</span></button>'
        . '<p class="hint">cPanel puts the account name in front of both names, e.g. <code>cpuser_alibi</code>: use the full names it shows.</p>'
        . '</form>');
}

/** A plain-words reason a connection failed (never the password). */
function setup_db_problem(PDOException $e): string
{
    preg_match('/\[(\d{4})\]/', $e->getMessage(), $m);
    switch ((int) ($m[1] ?? $e->getCode())) {
        case 1045: return 'The database user or password isn\'t right. Check them in cPanel → MySQL Databases (you can set a new password for the user there).';
        // MySQL gives this one both when the database doesn't exist and when the user has no access to it.
        case 1044: return 'This user can\'t open that database. Check the database name is the full one cPanel shows (e.g. cpuser_alibi), '
            . 'and that the user is added to it: cPanel → MySQL Databases → Add User To Database, tick All Privileges.';
        case 1049: return 'There\'s no database with that name. Use the full name cPanel shows, with the account prefix (e.g. cpuser_alibi).';
        case 2002:
        case 2003:
        case 2005:
        case 2006: return 'MySQL didn\'t answer at that server address. On cPanel hosting the host is localhost.';
    }
    return 'The database refused the connection (' . ($m[1] ?? $e->getCode()) . '). Check the details in cPanel → MySQL Databases.';
}
