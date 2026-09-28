<?php
/**
 * Chat history for the chat assistant.
 *
 * One JSON line per question and answer, one file per day, in
 * data_dir()/chats/ (api/data/chats/ by default; private). Each line carries an
 * anonymous conversation id from the visitor's browser tab, so a whole chat can
 * be followed; no names, emails or IP addresses are stored. Files older than
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

/** Saves one question and answer. $ordered: labels of dishes the chat added to the cart. */
function log_chat(string $cid, string $question, string $reply, array $usage, float $seconds, array $ordered = []): void
{
    if (!config()['chat_log']) return;
    $dir = chat_log_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $row = [
        'time'          => date('c'),
        'cid'           => $cid,
        'question'      => $question,
        'reply'         => $reply,
        'ordered'       => array_values($ordered),
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

/** Deletes days older than config 'chat_log_days'. */
function chat_log_cleanup(): void
{
    $keep = max(1, (int) config()['chat_log_days']);
    $cutoff = date('Y-m-d', strtotime("-$keep days"));
    foreach (glob(chat_log_dir() . '/*.jsonl') ?: [] as $file) {
        if (basename($file, '.jsonl') < $cutoff) @unlink($file);
    }
}

/**
 * Conversations with activity in the last $days days, most recent first:
 * [cid => ['cid', 'start', 'end', 'turns' => [row, ...]]].
 */
function chat_log_conversations(int $days): array
{
    $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
    $files = glob(chat_log_dir() . '/*.jsonl') ?: [];
    sort($files);
    $convos = [];
    foreach ($files as $file) {
        if (basename($file, '.jsonl') < $from) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $cid = (string) ($row['cid'] ?? 'unknown');
            $convos[$cid]['cid'] = $cid;
            $convos[$cid]['start'] = $convos[$cid]['start'] ?? $row['time'];
            $convos[$cid]['end'] = $row['time'];
            $convos[$cid]['turns'][] = $row;
        }
    }
    uasort($convos, fn($a, $b) => strcmp($b['end'], $a['end']));
    return $convos;
}
