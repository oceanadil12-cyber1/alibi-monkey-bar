<?php
/**
 * Square credentials for online ordering.
 *
 * 1. Copy this file to config.php (same folder).
 * 2. Log in at https://developer.squareup.com with the restaurant's Square
 *    account → Create an application → open it.
 * 3. Fill in the values below. Start with 'sandbox' (test cards, no real
 *    money) and switch to 'production' with the production values when ready.
 *
 * Never share config.php or commit it anywhere — the access token can take
 * payments on the restaurant's account.
 */
return [
    // 'sandbox' for testing, 'production' for real orders.
    'environment'    => 'sandbox',

    // Developer Dashboard → your app → Credentials → Application ID
    // (sandbox IDs start with "sandbox-sq0idb-", production with "sq0idp-").
    'application_id' => '',

    // Developer Dashboard → your app → Credentials → Access token.
    'access_token'   => '',

    // Developer Dashboard → your app → Locations. The restaurant's production
    // location is 9E9MT9QP4KND5; sandbox has its own test location ID.
    'location_id'    => '9E9MT9QP4KND5',

    // How long (minutes) to reuse the menu before re-reading it from Square.
    'menu_cache_mins' => 15,

    // Where contact messages and reservation requests are emailed.
    // (Everything is also saved in api/data/*.csv on the server.)
    'notify_email'   => '',

    // Sender address for those emails — use an address on the website's own
    // domain (e.g. website@alibiwiltonmanors.com) so they don't land in spam.
    'from_email'     => '',

    // Folder for saved form submissions (contact, reservations, newsletter).
    // Leave empty to use api/data/ (kept private by api/.htaccess on Apache).
    // On hosts that ignore .htaccess (e.g. Nginx), use a folder OUTSIDE the
    // public web root, e.g. '/home/USERNAME/alibi-data'.
    'data_dir'       => '',
];
