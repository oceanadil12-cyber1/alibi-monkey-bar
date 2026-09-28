# API Reference

PHP endpoints in `api/`. They all return JSON. Errors look like
`{ "ok": false, "error": "Message shown to the customer" }` and come with a
matching HTTP status code.

**Requirements:** PHP 7.4+ with the `curl` and `mbstring` extensions. `api/cache/`
and `api/data/` must be writable, and are created automatically.

## Configuration: `api/config.php`

Copy `api/config.example.php` to `api/config.php`. That file is **git-ignored and
must stay private**.

| Key | Meaning |
| --- | --- |
| `environment` | `sandbox` (test cards) or `production` (real money) |
| `application_id` | Square Application ID for that environment |
| `access_token` | Square access token. **Secret**: it can take payments |
| `location_id` | Square location. Production is `9E9MT9QP4KND5` |
| `menu_cache_mins` | How long to reuse the menu before re-reading Square (default 15) |
| `notify_email` | Where contact and reservation messages are emailed |
| `from_email` | Sender address. Use one on the site's own domain |
| `data_dir` | Where CSVs are saved. Empty means `api/data/`. On Nginx hosts, use a folder outside the web root |

With no `config.php`, the menu still works, because it uses the public store
feed. Checkout replies "Online payment is being set up", and forms still save to CSV.

## Shared protections (`lib.php`)

- **Same-site only:** POST endpoints require `Content-Type: application/json`,
  and requests from other websites are rejected (403).
- **Rate limiting (per visitor IP):** forms allow 8 submissions per 10 minutes,
  and payments allow 8 attempts per 15 minutes (a guard against card testing).
  Past that, the visitor gets HTTP 429 ("Too many attempts…").
- **Input cleaning:** every field is trimmed and length-limited before it is
  saved or emailed.

## `GET api/menu.php`

Returns the live menu built from the restaurant's Square Online store feed:
categories, items, sizes, option groups, prices (in cents), pickup and delivery
hours, prep time, delivery ZIPs and minimums.

- Cached in `api/cache/` for `menu_cache_mins`.
- If Square can't be reached, it serves the last cached copy (even if expired),
  then `data/menu.json`. If none of those exist, it returns **503**.
- The response also includes `paymentsEnabled` and, when that's true, the
  public `applicationId` that the card field needs.
- From the command line, `php api/menu.php --snapshot` rewrites
  `data/menu.json` from the live data.

## `POST api/checkout.php`

Used by `js/order.js`. The `action` field chooses what happens:

```json
{ "action": "quote", "cart": [...], "fulfillment": {...} }

{ "action": "pay", "cart": [...], "fulfillment": {...}, "customer": {...},
  "tip": 350, "sourceId": "<card token from Square Web Payments SDK>",
  "idempotencyKey": "<uuid>" }
```

Each cart line looks like
`{ "itemId": "1513", "variationId": "<square id>", "qty": 2, "modifiers": ["<choice id>"], "note": "..." }`.
Prices are never read from the browser.

- **quote:** validates the cart and returns the subtotal, tax, delivery fee and
  total, calculated by Square.
- **pay:** runs the same checks, creates the Square order (it appears in the
  POS like any online order) and charges the card. The `idempotencyKey` means a
  double-click or retry can't charge twice.

Server-side checks, which never trust the browser:

- Every item and option is **re-priced from the live menu**, and required options are enforced.
- Items no longer on the menu are rejected, and orders are capped at 50 lines.
- **Pickup:** the kitchen must be open (restaurant time zone) and the order must meet the pickup minimum.
- **Delivery:** delivery must be enabled, the ZIP must be in the allowed list,
  the delivery minimum must be met, and delivery must be open.
- **Customer:** a name, a phone number with 10+ digits and a valid email are required.

Returns 405 for non-POST requests, 503 if the menu is unavailable or payments
aren't configured, and 400 with a customer-friendly message for validation errors.

## `POST api/contact.php`

Handles contact messages and reservation requests.

```json
{ "type": "contact", "name": "...", "email": "...", "phone": "...",
  "subject": "general", "message": "..." }
{ "type": "reservation", "name": "...", "email": "...", "phone": "...",
  "date": "YYYY-MM-DD", "time": "...", "guests": "...", "area": "...", "notes": "..." }
```

- Reservations need a valid phone number, a date from today onward, a time and a party size.
- Each submission is saved to `contact.csv` or `reservations.csv` and emailed to `notify_email`.
- It succeeds if either the save or the email works. If both fail, it returns
  500 with a "please call us" message.

## `POST api/subscribe.php`

Handles newsletter sign-ups: `{ "email": "...", "page": "..." }`. The `page`
field records which page the visitor signed up from.

- Saves the address to `newsletter.csv`.
- When Square is configured, also adds the person to the **Square Customer
  Directory**, so the restaurant can email them with Square Marketing.

## `POST api/chat.php`

The "Ask us" chat assistant, used by `js/chat.js`.

```json
{ "messages": [ { "role": "user", "content": "What's on tonight?" },
                { "role": "assistant", "content": "..." },
                { "role": "user", "content": "..." } ] }
→ { "ok": true, "reply": "..." }
```

- The browser sends the whole conversation, because the server keeps no chat
  state. The server keeps the last 12 messages and limits each guest message to
  600 characters.
- The system prompt is built on every request. It always includes the rules
  in `chat.php`, `data/chatbot-knowledge.md` and Happy Hour from `menu.html`.
  The weekly lineup (`events.html`), drinks (`menu.html`) and food menu
  (`load_menu()`) are added only when the guest's last two messages mention
  them (keyword lists in `CHAT_TOPICS`). This keeps a request to roughly
  1,700–4,000 tokens, which matters on free plans that limit tokens per minute.
  The current time and tonight's shows go on the newest message instead.
- `chat_provider` picks the AI: `mistral`, `groq` or `gemini` (free plans), `ollama`
  (local), or `claude` (`chat-claude.php`; needs PHP 8.1+ and `api/vendor/`
  from Composer). If it fails, each AI in `chat_fallback` is tried in order.
  A specific model is written as `groq:openai/gpt-oss-20b`. Each Groq model
  has its own free limit.
- **Food ordering:** when the food menu is in the prompt, the AI is offered the
  `add_to_order` tool (`chat-order.php`, OpenAI-style providers only). The
  request also carries the guest's cart (`cart`, same line format as
  `js/order.js`), so dishes aren't added twice. `resolve_order()` matches dish,
  size and option names against the live menu and adds only fully specified
  dishes. Anything missing or ambiguous goes back to the AI, which asks the
  guest. The reply then includes `cart` (lines plus `label` and `total`).
  `js/chat.js` adds them to the site's cart through `window.alibiOrder` (menu
  page) or localStorage, and "Review & pay" opens the normal checkout
  (`menu.html#order`). Nothing is charged in the chat, and checkout re-prices
  everything.
- Protections: same-site JSON only, 30 messages per visitor per 10 minutes, and
  `chat_daily_limit` messages per day for the whole site (then 503).
- Every exchange is logged with token counts to `api/data/chat-log.csv` when
  `chat_log` is on.
- Errors return 502 with a "please call us" message. On `localhost` the reply
  also includes `detail` with the real cause.
- CLI: `php api/chat.php --prompt` prints the prompt, and
  `php api/chat.php "question"` asks one question.

## Testing locally

```bash
php -S localhost:3456 -t .
curl http://localhost:3456/api/menu.php
```

For payments, use `environment => 'sandbox'` and Square's test card
`4111 1111 1111 1111`, any future expiry, CVV `111`, ZIP `33305`.
