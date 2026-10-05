<?php
/**
 * Router for PHP's built-in dev server.
 * Handles clean URLs that Apache .htaccess would normally manage.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Dine-in: /dine-in/table/{number}
if (preg_match('#^/dine-in/table/(\d+)/?$#', $uri, $m)) {
    $_GET['table'] = $m[1];
    require __DIR__ . '/dine-in/index.php';
    return true;
}

// Let PHP's built-in server handle static files and existing PHP scripts
return false;
