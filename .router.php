<?php
/**
 * Router for PHP's built-in dev server.
 * Handles clean URLs that Apache .htaccess would normally manage, and keeps
 * private the same files .htaccess does (this server ignores .htaccess).
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Private files. Windows ignores case, trailing dots and spaces, so compare a cleaned-up path.
$path = strtolower(rtrim(str_replace('\\', '/', rawurldecode((string) $uri)), '. '));
$path = preg_replace('#/+#', '/', $path);
if (strpos($path, ':') !== false
    || preg_match('#(^|/)\.#', $path) // dot-files and folders: .git, .env, .htaccess...
    || preg_match('#\.(md|log|bak|sql|ini|sh|zip|bat|ps1)(/|$)#', $path)
    || preg_match('#^/(docs|temp-performers|admin/inc|api/data|api/cache|api/vendor)(/|$)#', $path)
    || preg_match('#^/api/(config(\.example)?|site-settings|lib|db|chat-claude|chat-order|chat-log|chat-cards|chat-insights|composer)\.(php|json|lock)(/|$)#', $path)
    || preg_match('#^/make-site-settings\.php(/|$)#', $path)) {
    require __DIR__ . '/404.php';
    return true;
}

// Dine-in: /dine-in/table/{number}
if (preg_match('#^/dine-in/table/(\d+)/?$#', $uri, $m)) {
    $_GET['table'] = $m[1];
    require __DIR__ . '/dine-in/index.php';
    return true;
}

// Let PHP's built-in server handle static files and existing PHP scripts
return false;
