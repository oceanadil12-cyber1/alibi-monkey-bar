<?php
/**
 * Customer-facing dine-in ordering — entry point.
 *
 * Reached via /dine-in/table/{N}. The URL never changes during the
 * entire flow (welcome → menu → cart → confirmation → reset).
 */

// Apache rewrites /dine-in/table/{N} → ?table=N. For PHP's built-in dev
// server (no .htaccess), fall back to parsing REQUEST_URI directly.
$table = (int) ($_GET['table'] ?? 0);
if ($table < 1 && isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/dine-in/table/(\d+)#', $_SERVER['REQUEST_URI'], $m)) {
        $table = (int) $m[1];
    }
}
if ($table < 1 || $table > 999) {
    http_response_code(404);
    readfile(__DIR__ . '/../404.html');
    exit;
}

require_once __DIR__ . '/../api/lib.php';
$menu = [];
require_once __DIR__ . '/../api/menu.php';
$menuData = load_menu();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Dine-In — Table <?= $table ?> | Alibi Monkey Bar</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0A0A0A">
  <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png">
  <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
  <link rel="preload" href="/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/fonts/playfair-display-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/css/style.css?v=20261002">
  <link rel="stylesheet" href="/dine-in/assets/dine-in.css?v=1">
</head>
<body>

  <div id="dinein-app" data-table="<?= $table ?>">

    <!-- SCREEN: Welcome / Name Entry -->
    <div class="dinein-screen dinein-screen--active" id="screen-welcome">
      <div class="dinein-welcome">
        <div class="dinein-welcome-logo">
          <img src="/images/logo.webp" alt="Georgie's Alibi Monkey Bar" width="280" height="171">
        </div>
        <div class="dinein-welcome-card">
          <div class="dinein-table-badge">
            <span class="dinein-table-label">Table</span>
            <span class="dinein-table-number"><?= $table ?></span>
          </div>
          <h1>Welcome!</h1>
          <p>Enter your first name to start ordering from your table.</p>
          <form id="dinein-name-form" autocomplete="off" novalidate>
            <div class="dinein-field">
              <label for="dinein-name">Your First Name</label>
              <input type="text" id="dinein-name" name="name"
                     placeholder="e.g. Alex"
                     maxlength="30"
                     pattern="[A-Za-z ]+"
                     autocomplete="given-name"
                     required>
              <span class="dinein-field-error" id="dinein-name-error"></span>
            </div>
            <button type="submit" class="dinein-btn dinein-btn--primary" id="dinein-start-btn">
              Start Ordering
            </button>
          </form>
        </div>
        <p class="dinein-welcome-footer">
          Georgie's Alibi Monkey Bar &middot; 2266 Wilton Drive, Wilton Manors
        </p>
      </div>
    </div>

    <!-- SCREEN: Menu (built in Option 2) -->
    <div class="dinein-screen" id="screen-menu"></div>

    <!-- SCREEN: Order Confirmation (built in Option 4) -->
    <div class="dinein-screen" id="screen-confirmation"></div>

  </div>

  <script src="/dine-in/assets/dine-in.js?v=1"></script>
</body>
</html>
