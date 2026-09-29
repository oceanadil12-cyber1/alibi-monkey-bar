<?php
/**
 * Sign-in for the admin panel: one shared password (config 'admin_password', or
 * the older 'chat_admin_password'), a PHP session cookie, a CSRF token on every
 * form, and a limit on password guesses. Nothing here is reachable without the
 * password except the sign-in page itself.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

const ADMIN_IDLE_SECONDS = 43200; // signed out after 12 hours without a click

/** The password from config.php; '' means the panel is switched off. */
function admin_password(): string
{
    $c = config();
    return (string) ($c['admin_password'] !== '' ? $c['admin_password'] : $c['chat_admin_password']);
}

/** Headers for every admin page: never cached, never indexed, never framed, no outside scripts. */
function admin_send_headers(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}

function admin_session_start(): void
{
    // Keep sessions in the private data folder so the host's own cleanup (often
    // 24 minutes) doesn't sign the team out and other sites can't read them.
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) ADMIN_IDLE_SECONDS);
    ini_set('session.use_strict_mode', '1');
    session_name('alibi_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => ADMIN_BASE,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/** Is this browser signed in? A real click ($touch) keeps the session alive; the background badge refresh doesn't. */
function admin_signed_in(bool $touch = true): bool
{
    if (empty($_SESSION['admin_ok'])) return false;
    if (time() - (int) ($_SESSION['admin_seen'] ?? 0) > ADMIN_IDLE_SECONDS) {
        $_SESSION = [];
        return false;
    }
    if ($touch) $_SESSION['admin_seen'] = time();
    return true;
}

function admin_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(admin_csrf()) . '">';
}

/** Stop a POST that didn't come from one of our own forms (a stale page or a forged request). */
function require_csrf(): void
{
    if (!hash_equals(admin_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        flash('That page had expired, so nothing was changed. Please try again.', 'err');
        redirect(admin_url((string) ($_GET['page'] ?? '')));
    }
}

/** The sign-in page (or the redirect after a right password). Always ends the request. */
function admin_login(string $password): void
{
    $error = '';
    if (is_post() && isset($_POST['password']) && is_string($_POST['password'])) {
        if (rate_limited('admin-login', 10, 900)) {
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } elseif (hash_equals($password, $_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['admin_seen'] = time();
            unset($_SESSION['csrf']);
            admin_log('login');
            redirect(ADMIN_BASE . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
        } else {
            admin_log('login.failed');
            $error = 'That password isn\'t right.';
        }
    }
    admin_render_bare('Sign in',
        '<form class="login" method="post">'
        . '<div class="login-logo"><img src="' . h(SITE_BASE) . 'images/logo.webp" alt="Georgie\'s Alibi Monkey Bar" height="56"></div>'
        . '<h1>Admin sign in</h1><p class="muted">Private area for the Alibi team.</p>'
        . ($error !== '' ? '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($error) . '</span></div>' : '')
        . '<input type="text" name="username" value="admin" autocomplete="username" hidden>'
        . '<label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button class="btn primary block" type="submit">' . icon('lock', 16) . '<span>Sign in</span></button>'
        . '</form>');
}

/** Sign out: forget the session and go back to the sign-in page. */
function admin_logout(): void
{
    admin_log('logout');
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    redirect(ADMIN_BASE);
}
