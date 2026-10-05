<?php
/**
 * Loads everything the admin panel needs. Included by admin/index.php only:
 * the guard below stops these files running if someone requests them directly
 * (admin/.htaccess also blocks the folder on Apache).
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

// The same libraries the public site uses, so the admin edits exactly what the
// website reads (menu overrides, store settings, Happy Hour) and the logs it writes.
require_once __DIR__ . '/../../api/lib.php';
require_once __DIR__ . '/../../api/chat-log.php';        // chat_log_conversations(), geo_distance()
require_once __DIR__ . '/../../api/menu.php';            // load_menu_base(), load_menu()
require_once __DIR__ . '/../../api/menu-overrides.php';  // read/write local menu overrides + store settings
require_once __DIR__ . '/../../api/happy-hour.php';      // happy_hour_data(), write_happy_hour()

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

// URL prefixes, computed from the request so the panel works from any folder
// (and with or without a trailing slash on /admin).
define('ADMIN_BASE', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/\\') . '/');
define('SITE_BASE', rtrim(dirname(rtrim(ADMIN_BASE, '/')), '/\\') . '/');

/** Where each section lives: page name => file in admin/inc/pages/ (the whitelist for ?page=). */
const ADMIN_PAGES = [
    'dashboard', 'orders', 'dine-in', 'waiter-assistance', 'reservations', 'messages', 'subscribers',
    'chats', 'insights', 'knowledge', 'events', 'performers', 'menu', 'happy-hour', 'settings', 'activity',
];
