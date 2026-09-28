<?php
/**
 * Shared helpers for the online-ordering API.
 *
 * Menu data comes from the restaurant's public Square Online store feed
 * (the same data georgies-alibi-monkey-bar.square.site shows). Orders and
 * payments go through Square's official Orders & Payments APIs using the
 * credentials in config.php (see config.example.php).
 */

date_default_timezone_set('America/New_York'); // restaurant time for saved submissions

const STORE_FEED ='https://cdn5.editmysite.com/app/store/api/v28/editor/users/124721230/sites/684991061342937356';
const STORE_LOCATION = '9E9MT9QP4KND5';
const CACHE_DIR = __DIR__ . '/cache';

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? require $file : [];
        $cfg += [
            'environment'     => 'sandbox',
            'application_id'  => '',
            'access_token'    => '',
            'location_id'     => STORE_LOCATION,
            'menu_cache_mins' => 15,
            'notify_email'    => '',
            'from_email'      => '',
            'data_dir'        => '',
            'chat_provider'     => 'ollama',
            'ollama_url'        => 'http://localhost:11434',
            'ollama_model'      => 'llama3.2:3b',
            'anthropic_api_key' => '',
            'claude_model'      => 'claude-opus-5',
            'chat_fallback'     => '',
            'mistral_api_key'   => '',
            'mistral_model'     => 'ministral-14b-latest',
            'groq_api_key'      => '',
            'groq_model'        => 'openai/gpt-oss-120b',
            'gemini_api_key'    => '',
            'gemini_model'      => 'gemini-3.8-flash',
            'chat_daily_limit'  => 500,
            'chat_log'          => true,
        ];
    }
    return $cfg;
}

/** True once real Square credentials have been added to config.php. */
function payments_enabled(): bool
{
    $c = config();
    return $c['application_id'] !== '' && $c['access_token'] !== '' && $c['location_id'] !== '';
}

function square_base(): string
{
    return config()['environment'] === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';
}

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): void
{
    send_json(['ok' => false, 'error' => $message], $status);
}

/** cURL handle with sane defaults (uses the OS certificate store when available). */
function curl_handle(string $url, array $opts = [])
{
    $ch = curl_init($url);
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'AlibiWebsite/1.0',
    ];
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        $defaults[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts + $defaults);
    return $ch;
}

/**
 * GET several public-feed URLs, a few at a time (the feed rate-limits bursts),
 * retrying failures once. Returns decoded JSON keyed like $urls (null on failure).
 */
function feed_get_many(array $urls, int $batchSize = 10): array
{
    $out = [];
    $pending = $urls;
    for ($attempt = 0; $attempt < 2 && $pending; $attempt++) {
        if ($attempt > 0) usleep(1500000);
        $failed = [];
        foreach (array_chunk($pending, $batchSize, true) as $batch) {
            $multi = curl_multi_init();
            $handles = [];
            foreach ($batch as $key => $url) {
                $handles[$key] = curl_handle($url);
                curl_multi_add_handle($multi, $handles[$key]);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 1.0);
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $data = $code === 200 ? json_decode((string) curl_multi_getcontent($ch), true) : null;
                if ($data === null) $failed[$key] = $batch[$key];
                $out[$key] = $data;
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }
        $pending = $failed;
    }
    return $out;
}

function feed_get(string $path)
{
    return feed_get_many(['x' => STORE_FEED . $path])['x'];
}

/** Call Square's REST API. Returns [httpStatus, decodedBody]. */
function square_request(string $method, string $path, ?array $body = null): array
{
    $c = config();
    $ch = curl_handle(square_base() . $path, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $c['access_token'],
            'Content-Type: application/json',
            'Square-Version: 2024-10-17',
        ],
        CURLOPT_POSTFIELDS    => $body === null ? null : json_encode($body),
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string) $res, true) ?? []];
}

function square_error_message(array $body): string
{
    $err = $body['errors'][0] ?? null;
    if (!$err) return 'Square did not accept the request.';
    $friendly = [
        'CARD_DECLINED'          => 'Your card was declined. Please try another card.',
        'CVV_FAILURE'            => 'The security code (CVV) is incorrect.',
        'ADDRESS_VERIFICATION_FAILURE' => 'The billing ZIP code does not match your card.',
        'INVALID_EXPIRATION'     => 'The card expiration date is invalid.',
        'INSUFFICIENT_FUNDS'     => 'Your card has insufficient funds.',
        'GENERIC_DECLINE'        => 'Your card was declined. Please try another card.',
    ];
    return $friendly[$err['code'] ?? ''] ?? ($err['detail'] ?? 'Payment could not be completed.');
}

// ---------- form submissions (contact, reservations, newsletter) ----------

/**
 * Where form submissions are saved. Defaults to api/data/ (blocked by
 * api/.htaccess on Apache); set 'data_dir' in config.php to a folder outside the
 * public web root for hosts that ignore .htaccess (e.g. Nginx).
 */
function data_dir(): string
{
    return rtrim((string) (config()['data_dir'] ?? ''), '/\\') ?: __DIR__ . '/data';
}

/**
 * Only accept JSON posted by this website's own pages. Other sites can't send
 * JSON here without a CORS preflight (which we never allow), and a browser's
 * Origin header must match this host when present.
 */
function require_same_site(): void
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) fail('Invalid request.', 415);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $from = parse_url($origin, PHP_URL_HOST);
        $self = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        if (!$from || strcasecmp($from, $self) !== 0) fail('Invalid request.', 403);
    }
}

/**
 * Allow at most $max requests per $windowSeconds from one visitor (by IP) for a
 * bucket such as 'form' or 'pay'; stops spam floods and card-testing. Counts are
 * kept in api/cache/rate/ (private). A disk problem never blocks a customer.
 */
function rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    $dir = CACHE_DIR . '/rate';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = time();
    if (mt_rand(1, 100) === 1) { // tidy up old counters now and then
        foreach (glob("$dir/*.json") ?: [] as $old) if ($now - @filemtime($old) > 86400) @unlink($old);
    }
    $fh = @fopen("$dir/$bucket-" . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '') . '.json', 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $hits = array_values(array_filter(json_decode((string) stream_get_contents($fh), true) ?: [], fn($t) => $t > $now - $windowSeconds));
    $blocked = count($hits) >= $max;
    if (!$blocked) {
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($blocked) fail('Too many attempts. Please wait a few minutes and try again, or call us at (954) 565-2526.', 429);
}

/** Parse a JSON POST body; basic bot checks shared by the public forms. */
function read_form(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
    require_same_site();
    rate_limit('form', 8, 600);
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) fail('Invalid request.');
    // Honeypot field (hidden from people) and a minimum time on the page.
    if (!empty($in['website'])) send_json(['ok' => true]);
    if (isset($in['startedAt']) && (time() * 1000 - (int) $in['startedAt']) < 2000) send_json(['ok' => true]);
    return $in;
}

function clean_field($value, int $max = 200): string
{
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value));
    return mb_substr($value, 0, $max);
}

/** Append a row to a private CSV in api/data/ (blocked from the web by .htaccess). */
function save_row(string $file, array $row): bool
{
    if (!is_dir(data_dir())) @mkdir(data_dir(), 0750, true);
    $path = data_dir() . "/$file.csv";
    $new = !is_file($path);
    $fh = @fopen($path, 'a');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    // Neutralise spreadsheet formulas in user input.
    $safe = array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row);
    if ($new) fputcsv($fh, array_keys($row));
    fputcsv($fh, array_values($safe));
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

/** Email the restaurant (config notify_email). Returns false if not configured or mail fails. */
function notify(string $subject, array $fields, string $replyTo = ''): bool
{
    $to = trim((string) (config()['notify_email'] ?? ''));
    if ($to === '') return false;
    $body = '';
    foreach ($fields as $k => $v) $body .= str_pad($k . ':', 12) . str_replace("\n", "\n            ", (string) $v) . "\n";
    $host = preg_replace('/[^a-z0-9.\-]/i', '', preg_replace('/^www\.|:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
    $from = trim((string) (config()['from_email'] ?? '')) ?: "website@$host";
    $headers = "From: Alibi Website <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers .= "Reply-To: $replyTo\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function cache_read(string $name, int $maxAgeSeconds)
{
    $file = CACHE_DIR . "/$name.json";
    if (is_file($file) && (time() - filemtime($file)) < $maxAgeSeconds) {
        return json_decode((string) file_get_contents($file), true);
    }
    return null;
}

function cache_write(string $name, $data): void
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    @file_put_contents(CACHE_DIR . "/$name.json", json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
