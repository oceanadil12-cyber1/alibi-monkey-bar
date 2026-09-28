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

    // ---------- Chat assistant (js/chat.js → api/chat.php) ----------

    // Which AI answers the chat:
    //   'ollama' — free, runs on your own computer; for testing (https://ollama.com)
    //   'mistral' — Mistral's API; free plan with high limits (about 1 question
    //              per second). Key from https://console.mistral.ai/api-keys
    //   'groq'   — Groq's API; free plan (about 1,000 questions/day), very fast.
    //              Key from https://console.groq.com/keys
    //   'gemini' — Google's API; small free plan (about 20 questions/day).
    //              Key from https://aistudio.google.com/apikey
    //   'claude' — Anthropic's API; paid. Needs PHP 8.1+ and the
    //              Anthropic SDK: run  composer require anthropic-ai/sdk  in api/
    'chat_provider'     => 'ollama',

    // A second AI to ask when the first one fails (free limit reached, busy or
    // down), e.g. 'gemini'. Leave empty for none.
    'chat_fallback'     => '',

    // Mistral settings. On the free plan, turn off "Anonymous improvement data"
    // in admin.mistral.ai (API section → Privacy) so chats aren't used for
    // training, and don't enable Labs models.
    'mistral_api_key'   => '',
    'mistral_model'     => 'mistral-small-latest',

    // Groq settings. In the Groq console, turn on Zero Data Retention
    // (Settings → Data Controls) so guests' chats are never stored.
    'groq_api_key'      => '',
    'groq_model'        => 'openai/gpt-oss-120b',

    // Gemini settings. On Google's free plan, Google may use the chats to improve
    // its products, so use a paid plan (or Claude) for the live site.
    'gemini_api_key'    => '',
    'gemini_model'      => 'gemini-3.8-flash',

    // Ollama settings (the model must be downloaded first: ollama pull llama3.2:3b).
    'ollama_url'        => 'http://localhost:11434',
    'ollama_model'      => 'llama3.2:3b',

    // Claude settings. Create the key at https://console.anthropic.com with the
    // restaurant's own account (it is billed per message; set a monthly spend
    // limit there). Model options, most capable to cheapest:
    // 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'.
    'anthropic_api_key' => '',
    'claude_model'      => 'claude-opus-5',

    // Most chat messages the whole site will answer per day (0 = no limit).
    // A safety net against spam running up the AI bill.
    'chat_daily_limit'  => 500,

    // Save each question, answer and token count to api/data/chat-log.csv
    // (private) so you can see what guests ask and what it costs.
    'chat_log'          => true,
];
