<?php
/**
 * Chat history for the chat assistant.
 *
 * One JSON line per question and answer, one file per day, in
 * data_dir()/chats/ (api/data/chats/ by default; private). Each line carries a
 * conversation id from the visitor's browser tab, so a whole chat can be
 * followed, the email (required) and name (optional) the guest gave before their
 * first message, and the visitor's IP, device and browser. Files older than
 * config 'chat_log_days' (120) are deleted automatically.
 *
 * Written by chat.php, read by chats.php (the password-protected history page).
 */

function chat_log_dir(): string
{
    return data_dir() . '/chats';
}

/** A conversation id from the browser, or 'unknown' if it isn't a plausible one. */
function chat_log_cid($raw): string
{
    return is_string($raw) && preg_match('/^[A-Za-z0-9-]{6,40}$/', $raw) ? $raw : 'unknown';
}

/** The email (required, validated) and name (optional) the guest gave, cleaned. */
function chat_contact($in): array
{
    $email = clean_field(is_array($in) ? ($in['email'] ?? '') : '', 120);
    $name  = clean_field(is_array($in) ? ($in['name'] ?? '') : '', 80);
    return [
        'name'  => $name,
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
    ];
}

/** The visitor's IP, device (Mobile/Tablet/Desktop) and browser, from the request. */
function chat_client_meta(): array
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return ['ip' => client_ip(), 'device' => ua_device($ua), 'browser' => ua_browser($ua)];
}

/** Rough device class from a User-Agent string. */
function ua_device(string $ua): string
{
    if ($ua === '') return 'Unknown';
    if (preg_match('/iPad|Tablet|PlayBook|Kindle|Silk/i', $ua)
        || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) return 'Tablet';
    if (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini/i', $ua)) return 'Mobile';
    return 'Desktop';
}

/** Browser name from a User-Agent string (checked most specific first). */
function ua_browser(string $ua): string
{
    if ($ua === '') return 'Unknown';
    $checks = [
        'Edge'             => '/\bEdg(e|iOS|A)?\//',
        'Opera'            => '/\bOPR\/|\bOpera\b/',
        'Samsung Internet' => '/SamsungBrowser/',
        'Chrome'           => '/\bCriOS\/|\bChrome\//',
        'Firefox'          => '/\bFxiOS\/|\bFirefox\//',
        'Safari'           => '/\bSafari\//',
        'Internet Explorer' => '/\bMSIE |\bTrident\//',
    ];
    foreach ($checks as $name => $re) if (preg_match($re, $ua)) return $name;
    return 'Other';
}

/**
 * Saves one question and answer. $contact: ['name', 'email'] the guest gave;
 * $meta: ['ip', 'device', 'browser'] from the request; $ordered: labels of
 * dishes the chat added to the cart; $shows: names of shows its cards showed
 * (both for the Insights tab).
 */
function log_chat(string $cid, string $question, string $reply, array $usage, float $seconds, array $contact = [], array $meta = [], array $ordered = [], array $shows = []): void
{
    if (!config()['chat_log']) return;
    $dir = chat_log_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $row = [
        'time'          => date('c'),
        'cid'           => $cid,
        'name'          => (string) ($contact['name'] ?? ''),
        'email'         => (string) ($contact['email'] ?? ''),
        'ip'            => (string) ($meta['ip'] ?? ''),
        'device'        => (string) ($meta['device'] ?? ''),
        'browser'       => (string) ($meta['browser'] ?? ''),
        'question'      => $question,
        'reply'         => $reply,
        'ordered'       => array_values($ordered),
        'shows'         => array_values($shows),
        'provider'      => $usage['provider'] ?? config()['chat_provider'],
        'model'         => $usage['model'] ?? '',
        'input_tokens'  => $usage['input_tokens'] ?? 0,
        'cache_read'    => $usage['cache_read'] ?? 0,
        'output_tokens' => $usage['output_tokens'] ?? 0,
        'seconds'       => round($seconds, 1),
    ];
    @file_put_contents("$dir/" . date('Y-m-d') . '.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (mt_rand(1, 50) === 1) chat_log_cleanup();
}

/** Deletes days older than config 'chat_log_days', and stale geo-lookup cache. */
function chat_log_cleanup(): void
{
    $keep = max(1, (int) config()['chat_log_days']);
    $cutoff = date('Y-m-d', strtotime("-$keep days"));
    foreach (glob(chat_log_dir() . '/*.jsonl') ?: [] as $file) {
        if (basename($file, '.jsonl') < $cutoff) @unlink($file);
    }
    foreach (glob(CACHE_DIR . '/geo/*.json') ?: [] as $file) {
        if (time() - @filemtime($file) > 5184000) @unlink($file); // 60 days
    }
}

/**
 * Conversations with activity in a period, most recent first:
 * [cid => ['cid', 'start', 'end', 'email', 'name', 'ip', 'device', 'browser', 'turns' => [row, ...]]].
 *
 * Either the last $days days (when $from/$to are null), or an explicit
 * $from..$to date range (YYYY-MM-DD, inclusive) for the date picker.
 */
function chat_log_conversations(int $days, ?string $from = null, ?string $to = null): array
{
    $from = $from ?: date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
    $to   = $to ?: date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    $files = glob(chat_log_dir() . '/*.jsonl') ?: [];
    sort($files);
    $convos = [];
    foreach ($files as $file) {
        $day = basename($file, '.jsonl');
        if ($day < $from || $day > $to) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $cid = (string) ($row['cid'] ?? 'unknown');
            $convos[$cid]['cid'] = $cid;
            $convos[$cid]['start'] = $convos[$cid]['start'] ?? $row['time'];
            $convos[$cid]['end'] = $row['time'];
            // These are the same for a whole conversation; keep the first
            // non-empty one seen (older logs won't have every field).
            foreach (['email', 'name', 'ip', 'device', 'browser'] as $k) {
                if (empty($convos[$cid][$k]) && !empty($row[$k])) $convos[$cid][$k] = $row[$k];
            }
            $convos[$cid]['turns'][] = $row;
        }
    }
    uasort($convos, fn($a, $b) => strcmp($b['end'], $a['end']));
    return $convos;
}

// ---------- location & distance ----------

/** The club's coordinates (config 'club_lat'/'club_lon', else 2266 Wilton Drive). */
function chat_club_coords(): array
{
    $c = config();
    $lat = (float) ($c['club_lat'] ?? 0);
    $lon = (float) ($c['club_lon'] ?? 0);
    return ($lat && $lon) ? [$lat, $lon] : [26.1503, -80.1426];
}

/** Great-circle distance in miles between two lat/lon points. */
function haversine_miles(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 3958.8; // Earth radius, miles
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Approximate location of an IP, from the free ip-api.com service (no key,
 * ~45 lookups/minute). Cached per IP in api/cache/geo/ for 30 days so the
 * history page never re-looks-up the same visitor, and never sends an IP twice.
 * Returns ['lat','lon','city','region','country'] or null (private/failed IP).
 */
function geo_locate(string $ip): ?array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
    // Skip private, loopback and reserved ranges: they can't be located.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;

    $dir = CACHE_DIR . '/geo';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = "$dir/" . hash('sha256', $ip) . '.json';
    if (is_file($file) && time() - (int) @filemtime($file) < 2592000) { // 30 days
        $cached = json_decode((string) @file_get_contents($file), true);
        return (is_array($cached) && !empty($cached['ok'])) ? $cached : null;
    }

    $ch = curl_handle('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,lat,lon,city,regionName,country',
        [CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3]);
    $res = curl_exec($ch);
    curl_close($ch);
    $body = json_decode((string) $res, true);
    $data = ['ok' => false];
    if (is_array($body) && ($body['status'] ?? '') === 'success' && isset($body['lat'], $body['lon'])) {
        $data = [
            'ok'      => true,
            'lat'     => (float) $body['lat'],
            'lon'     => (float) $body['lon'],
            'city'    => (string) ($body['city'] ?? ''),
            'region'  => (string) ($body['regionName'] ?? ''),
            'country' => (string) ($body['country'] ?? ''),
        ];
    }
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX); // cache misses too, so we don't retry
    return !empty($data['ok']) ? $data : null;
}

/** Approx distance from the club to an IP: ['miles', 'place'] or null. */
function geo_distance(string $ip): ?array
{
    $g = geo_locate($ip);
    if (!$g) return null;
    [$clat, $clon] = chat_club_coords();
    $place = implode(', ', array_filter([$g['city'] ?? '', $g['region'] ?? '', $g['country'] ?? '']));
    return ['miles' => haversine_miles($clat, $clon, (float) $g['lat'], (float) $g['lon']), 'place' => $place];
}
