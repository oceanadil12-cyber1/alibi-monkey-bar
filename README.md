# Alibi Monkey Bar — Website & Web App

The official website and web application for **Georgie's Alibi Monkey Bar**, a
restaurant, bar and entertainment venue at 2266 Wilton Drive, Wilton Manors,
FL 33305 (phone (954) 565-2526).

It is a fast, hand-built site: plain HTML, CSS and JavaScript, with no build
step and no framework. A small PHP backend powers three major systems:

1. **Live Square Menu & Online Ordering** — real-time menu from Square, cart,
   pickup/delivery, tips, and card payment.
2. **AI Chat Assistant** — a conversational assistant that answers guests'
   questions about hours, events, the menu and more, and can add food to the
   cart directly from the chat.
3. **Admin Panel** — a password-protected web app at `/admin/` for the
   restaurant team: orders, reservations, messages, newsletter sign-ups, the
   chat assistant, events (with poster uploads), the menu, Happy Hour and store
   settings — all without touching code.

> **Private repository.** This is client work. Do not make the repository
> public, and never commit `api/config.php`, which holds the Square access
> token, AI API keys, and the admin password.

---

## Table of contents

1. [How to display the website](#how-to-display-the-website)
2. [Pages](#pages)
3. [Online ordering system](#online-ordering-system)
4. [AI chat assistant](#ai-chat-assistant)
5. [Admin panel](#admin-panel)
6. [Project structure](#project-structure)
7. [Tech at a glance](#tech-at-a-glance)
8. [Security](#security)
9. [Configuration reference](#configuration-reference)
10. [Working with this repository](#working-with-this-repository)
11. [Documentation index](#documentation-index)
12. [Version history](#version-history)

---

## How to display the website

Pick the option that fits what you need.

| Option | What works | Needs |
| --- | --- | --- |
| **A. Double-click `Start Website.bat`** | Everything | Windows + XAMPP (or Node.js for a basic preview) |
| **B. PHP built-in server** | Everything: live menu, ordering, chat, admin, forms | PHP 7.4+ |
| **C. Static server (Node.js)** | All pages and design; menu from the saved snapshot | Node.js |
| **D. Open `index.html` directly** | Quick look only | Nothing |

### A. Easiest on Windows: double-click the launcher

1. Open the project folder.
2. Double-click **`Start Website.bat`**.
3. Your browser opens **<http://localhost:3456>**.
4. Keep the black window open while you browse. Close it to stop the site.

The launcher uses PHP from XAMPP (`C:\xampp\php\php.exe`) or from your `PATH`,
so everything works. If it can't find PHP, it falls back to Node.js
(option C).

### B. PHP built-in server (full site)

From the project folder, run:

```bash
php -S localhost:3456 -t .
# Windows with XAMPP:
C:\xampp\php\php.exe -S localhost:3456 -t .
```

Then open <http://localhost:3456>.

- The **menu page** loads the live menu from the restaurant's Square Online store.
- **Ordering and payment** also need Square credentials in `api/config.php`.
  Without them, checkout shows "Online payment is being set up". See
  [ORDERING-SETUP.md](ORDERING-SETUP.md).
- **Chat assistant** needs an AI provider configured in `api/config.php`.
- **Admin panel** needs `admin_password` set in `api/config.php`. Open
  <http://localhost:3456/admin/> and sign in.
- **Forms** save submissions to `api/data/*.csv`. Email notifications need a
  mail server, so they normally only arrive on the live host.

### C. Static server with Node.js (design preview)

```bash
npx serve . -l 3456
```

Then open <http://localhost:3456>. All pages, images, events, sliders and the
gallery display normally. The menu falls back to `data/menu.json`, and forms,
checkout, chat and the admin panel show a friendly "please call us" message,
because they need PHP.

### D. Open the file directly

Double-click `index.html`. The pages display, but browsers block some features
on `file://` pages (the home page's upcoming-events slider, the menu). Use A, B
or C for a real preview.

### Putting it on the internet

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**. In short: upload the site
files to a PHP host (cPanel or similar), create `api/config.php`, and turn on
HTTPS.

---

## Pages

| Page | File | Highlights |
| --- | --- | --- |
| Home | `index.html` | Hero slider, upcoming-events slider, awards, Google reviews, Instagram |
| Menu & ordering | `menu.html` | Live Square menu, cart, pickup/delivery, tips, card payment |
| Events | `events.html` | Weekly lineup with day tabs (opens on today) |
| Entertainment | `entertainment.html` | Shows and performers |
| Meet Our Dancers | `dancers.html` | Dancer roster |
| Private Events | `private-events.html` | Event hosting and enquiries |
| Gallery | `gallery.html` | Instagram feed with "See More Posts" paging |
| About | `about.html` | Story, awards |
| Contact | `contact.html` | Contact and reservation forms, map, hours |
| Not found | `404.html` | Branded error page |

---

## Online ordering system

The menu page (`menu.html` + `js/order.js` + `api/checkout.php`) provides a
complete ordering flow:

### How it works

1. **Menu loads** from the restaurant's Square Online store via `api/menu.php`.
   The menu is cached for 15 minutes. If Square is unreachable, it falls back to
   `data/menu.json`.
2. **Guest browses** the menu by category, taps items to add them to the cart.
   Items with options (sizes, extras) show a modal for choosing.
3. **Cart** is saved in `localStorage` (`alibi-cart-v1`) and persists across
   page refreshes within the same browser.
4. **Fulfillment:** guest chooses **Pickup** or **Delivery**.
   - Pickup: must be during kitchen hours and meet the pickup minimum.
   - Delivery: must be during delivery hours, the ZIP must be in the allowed
     list, and the subtotal must meet the delivery minimum.
5. **Per-item rules** (set in the Admin Panel):
   - **Pickup only:** item cannot be ordered for delivery.
   - **Delivery only:** item cannot be ordered for pickup.
   - **Delivery surcharge:** extra fee added to the delivery total for that item.
   - **Prep time:** overrides the default prep time for that item.
6. **Checkout:** the server re-prices every item from the live menu (tampered
   prices are ignored), calculates tax, delivery fee and tip, and charges the
   card through Square Web Payments SDK.
7. **Cash on delivery mode:** when no Square payment credentials are configured,
   the system switches to cash-on-delivery: no card charge, guests pay in cash
   on pickup or delivery. Tax is added in person.

### Key safety checks (server-side)

- Every item and option is re-priced from the live menu.
- Items no longer on the menu are rejected.
- Orders are capped at 50 lines.
- Required options are enforced.
- Pickup-only items are blocked from delivery orders.
- Delivery-only items are blocked from pickup orders.
- The `idempotencyKey` prevents double-charging on retries.

---

## AI chat assistant

The "Ask us" floating chat button appears on every page (`js/chat.js` +
`api/chat.php`). It answers guests' questions in natural language.

### What it knows

| Topic | Source | How to update |
| --- | --- | --- |
| Hours, policies, reservations, private events, FAQs | `data/chatbot-knowledge.md` | Edit the file (plain text) |
| Weekly show lineup | `events.html` | Edit the lineup on the Events page |
| Happy Hour and drinks | `menu.html` | Edit the page |
| Food menu, prices, pickup/delivery | Square | Change it in Square |
| Current time and tonight's shows | Injected per request | Automatic |

### How it works

- The browser sends the full conversation (last 12 messages, each capped at
  600 characters). The server keeps no chat state.
- The system prompt is assembled on every request. Core rules and FAQ are
  always included. The weekly lineup, drinks and food menu are added only when
  relevant (keyword matching), keeping token usage low (1,700-4,000 tokens).
- **AI providers** (configured in `api/config.php`):
  - **Free:** Groq, Gemini, Mistral (rate-limited free tiers)
  - **Local:** Ollama (for development and testing)
  - **Paid:** Claude (Anthropic API, needs PHP 8.1+ and Composer)
  - Fallback chain: if one provider fails, the next in `chat_fallback` is tried.

### Food ordering in the chat

When the food menu is relevant, the AI can use the `add_to_order` tool to add
dishes to the guest's cart directly from the conversation. The tool matches
dish, size and option names against the live menu. Anything missing or ambiguous
is asked back. The chat adds items to the same cart as the menu page, and
"Review & pay" opens the normal checkout. Nothing is charged in the chat.

### Cards and suggestions

Replies include interactive cards (show cards with poster, time and host;
party package cards) and up to three follow-up suggestion buttons. Cards are
built from the site's own pages, not from the AI's text.

### Protections

- Same-site JSON only, 30 messages per visitor per 10 minutes.
- `chat_daily_limit` caps total messages per day for the whole site (default
  500), preventing spam from running up the bill.
- Chat history is logged to `api/data/chats/` as JSONL files, one per day.
  Logs older than 120 days (`chat_log_days`) are auto-deleted.

---

## Admin panel

A password-protected web app at **`/admin/`** for the restaurant team, and the one
place to see what guests sent the website and to control what it shows. Sign in
with `admin_password` from `api/config.php` (the older name
`chat_admin_password` still works). It works on phones and tablets too. The full
guide is **[docs/ADMIN.md](docs/ADMIN.md)**.

| Section | What it does |
| --- | --- |
| **Dashboard** | What needs attention now (new orders, reservation requests, unread messages), the last two weeks, what the website is showing right now, setup checks, recent activity |
| **Orders** | Cash-on-delivery orders as they come in: one-tap status (New → Preparing → Ready → Completed), team notes, printable ticket, sales totals, spreadsheet export |
| **Reservations** | Table requests from the reservation form: confirm or decline with a ready-made email, notes, upcoming and past views |
| **Messages** | The contact-form inbox: unread, replied, archived; reply by email; delete spam |
| **Subscribers** | Newsletter sign-ups: search, export, remove |
| **Conversations** | Every chat with the assistant, with returning-guest and reservation-request flags, a follow-up flag and notes, and a spreadsheet export |
| **Insights** | What guests ask and order in the chat, and how orders are going (sales, busiest times, best sellers) |
| **Knowledge** | Edit what the chat assistant knows (`data/chatbot-knowledge.md`), with one-click restore of earlier versions |
| **Events** | Add weekly or one-night events with a poster (a size guide and cropping editor), host, description and tags, then publish them to the Events page and the home page slider |
| **Menu & food** | Hide items, change prices, pickup-only or delivery-only, delivery surcharge, prep time, edit descriptions and options, add your own dishes |
| **Happy Hour** | The Happy Hour menu, its times, and the scrolling ticker |
| **Store settings** | Business details, hours, delivery area and fees |
| **Activity log** | Who signed in and what they changed |

The sidebar shows live counts of new orders, reservation requests and messages.
Statuses, notes and flags that the team adds are kept beside the guests' records
(an order, reservation or message is never edited), and every change is logged.
`api/chats.php`, the old address of the chat history page, forwards to the panel,
so existing bookmarks keep working.

---

## Project structure

```
alibi-monkey-bar/
├── *.html                 Site pages (edit these directly; events.html's lineup is written by the admin)
├── admin/                 Admin panel (see docs/ADMIN.md)
│   ├── index.php          Sign-in, routing (?page=orders …)
│   ├── assets/            admin.css, admin.js, events.js (the poster editor)
│   └── inc/               Shared code, plus one file per section in pages/
├── css/style.css          All styling, including icons (inline SVG)
├── js/                    Page scripts (no libraries)
│   ├── main.js            Shared: header, mobile nav, hero slider, lazy load, forms
│   ├── order.js           Ordering flow: menu, cart, checkout, payment
│   ├── chat.js            AI chat assistant: button, panel, cards, food-to-cart
│   ├── events.js          Weekly lineup day tabs
│   ├── upcoming.js        Home page upcoming-events slider
│   ├── instagram-more.js  Gallery "See More Posts" paging
│   └── elfsight-badge.js  Elfsight widget badge cleanup
├── images/                WebP images: site/, events/, dancers/, awards, hero
├── fonts/                 Self-hosted Inter + Playfair Display (woff2)
├── data/
│   ├── menu.json          Menu snapshot (backup if Square is unreachable)
│   └── chatbot-knowledge.md  FAQ and facts for the chat assistant
├── api/                   PHP backend
│   ├── config.example.php Template for the private api/config.php
│   ├── config.php         (private, git-ignored) Square keys, AI keys, admin password
│   ├── lib.php            Shared helpers: config, Square API, JSON, rate limiting, CSV, email, caching
│   ├── menu.php           GET — live menu from Square (cached 15 min)
│   ├── menu-overrides.php Local menu adjustments (hide, re-price, pickup/delivery only, surcharge)
│   ├── checkout.php       POST — validate cart, calculate totals, charge card
│   ├── chat.php           POST — AI chat assistant endpoint
│   ├── chat-claude.php    Claude (Anthropic API) provider for the chat
│   ├── chat-order.php     add_to_order tool: match dishes to live menu, build cart
│   ├── chat-cards.php     Show/package cards and follow-up suggestions
│   ├── chat-log.php       Chat logging to JSONL files
│   ├── chat-insights.php  Analytics: topic/food/show/time breakdowns from logs
│   ├── chats.php          Old chat-history address; forwards to admin/
│   ├── happy-hour.php     Happy Hour data read/write
│   ├── contact.php        POST — contact and reservation forms
│   ├── subscribe.php      POST — newsletter sign-up (CSV + Square Customers)
│   ├── cache/             Auto-created: menu cache, rate-limit counters
│   └── data/              Auto-created: form CSVs, chat logs
├── docs/                  Developer documentation
│   ├── ARCHITECTURE.md    How the site is built
│   ├── CONTENT-GUIDE.md   How to change events, dancers, photos, text, hours, menu
│   ├── API.md             PHP endpoint reference
│   └── DEPLOYMENT.md      How to publish and update the live site
├── .htaccess              Apache: security, caching, clean URLs, 404 page
├── robots.txt
├── ORDERING-SETUP.md      Square ordering setup guide for the owner
├── Start Website.bat      Windows one-click launcher
└── Save to GitHub.bat     Windows one-click git commit and push
```

---

## Tech at a glance

| Layer | Technology |
| --- | --- |
| **Front end** | HTML5, one CSS file, vanilla JavaScript (ES2017+). No build tools or npm packages. |
| **Back end** | PHP 7.4+ with `curl` and `mbstring`. No database: data comes from Square, and submissions are saved to CSV/JSONL files. |
| **Ordering** | Square (menu feed, orders, payments, customer directory). Card details go straight into Square's hosted field and never touch this server. |
| **AI Chat** | Groq, Gemini, Mistral (free), Ollama (local), or Claude (paid). Tool-use for food ordering. |
| **Widgets** | Elfsight (Google reviews and Instagram feed). |
| **Performance** | WebP images in 640/1024/1600 widths, lazy loading, self-hosted fonts, long-lived caching with `?v=` cache-busting. |

---

## Security

- `api/.htaccess` blocks `config.php`, `lib.php`, `cache/` and `data/` from
  the web.
- The root `.htaccess` blocks `.md` files, dot-files and the `.git` folder,
  disables directory listings and sets security headers.
- The endpoints only accept same-site JSON POSTs and are rate-limited.
- Checkout **re-prices every cart item on the server** from the live menu, so
  a tampered browser can't change prices.
- Card details go straight into Square's hosted field and never touch this
  server.
- The admin panel is protected by a password, uses PHP session cookies
  (`HttpOnly`, `SameSite`), puts a CSRF token on every form, rate-limits
  sign-in attempts (10 per 15 minutes), and sends `noindex`, no-cache and
  Content-Security-Policy headers. Uploaded posters are checked to be real
  480 × 600 images.
- All guest text in the admin panel is HTML-escaped, and spreadsheet downloads
  neutralise formulas.
- Chat conversations need no account: guests give an email (and optionally a
  name) before chatting, and their IP address, device and browser are saved with
  the chat. Chats are deleted automatically after 120 days.

---

## Configuration reference

All configuration lives in `api/config.php` (not committed to git). Copy
`api/config.example.php` and fill in the values.

| Key | Purpose | Required |
| --- | --- | --- |
| `environment` | `sandbox` (test) or `production` (real money) | For payments |
| `application_id` | Square Application ID | For payments |
| `access_token` | Square access token (secret) | For payments |
| `location_id` | Square location ID | For payments |
| `menu_cache_mins` | Menu cache duration in minutes (default 15) | No |
| `notify_email` | Where contact/reservation emails are sent | For email |
| `from_email` | Sender address (use the site's domain) | For email |
| `data_dir` | Where CSVs/logs are saved (default `api/data/`) | No |
| `chat_provider` | AI provider: `groq`, `gemini`, `mistral`, `ollama`, `claude` | For chat |
| `chat_fallback` | Array of fallback providers tried in order | No |
| `groq_api_key` | Groq API key | For Groq chat |
| `gemini_api_key` | Gemini API key | For Gemini chat |
| `mistral_api_key` | Mistral API key | For Mistral chat |
| `anthropic_api_key` | Anthropic API key | For Claude chat |
| `claude_model` | Claude model name | For Claude chat |
| `chat_log` | Enable/disable chat logging (default on) | No |
| `chat_log_days` | Days to keep chat logs (default 120) | No |
| `chat_daily_limit` | Max messages per day site-wide (default 500) | No |
| `admin_password` | Admin panel password, 12+ characters (empty = panel disabled). The older name `chat_admin_password` still works | For admin |

---

## Working with this repository

```bash
git clone https://github.com/oceanadil12-cyber1/alibi-monkey-bar.git
cd alibi-monkey-bar
# then start it with one of the options above
```

### Saving your work to GitHub

- **Working with Claude Code:** changes are committed and pushed automatically
  every time Claude finishes a reply. A user-level Stop hook in
  `~/.claude/hooks/alibi-auto-push.sh` does this.
- **Editing by hand:** double-click **`Save to GitHub.bat`** to commit and push everything.
- Either way, `api/config.php` and customer data (`api/data/`) are never uploaded.

After editing `css/style.css` or any `js/` file, bump the `?v=YYYYMMDD` value
where the pages link to it, so visitors get the new version (see
[docs/CONTENT-GUIDE.md](docs/CONTENT-GUIDE.md#cache-busting)).

---

## Documentation index

| Document | Read it when you want to... |
| --- | --- |
| **This README** | understand the whole project: scope, features, architecture |
| [docs/ADMIN.md](docs/ADMIN.md) | use or extend the admin panel: orders, reservations, events and posters, menu, settings |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | understand how the site is built, page by page and script by script |
| [docs/CONTENT-GUIDE.md](docs/CONTENT-GUIDE.md) | change events, dancers, photos, text, hours or the menu |
| [docs/API.md](docs/API.md) | work on the PHP endpoints (menu, checkout, chat, forms) |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | publish or update the live site |
| [ORDERING-SETUP.md](ORDERING-SETUP.md) | connect Square payments (restaurant owner's steps) |

---

## Version history

| Version | Date | Highlights |
| --- | --- | --- |
| **v2.0** | 2026-09-29 | AI Chat & Admin Panel [Web App] — delivery-only fulfillment rule, comprehensive project documentation |
| **v1.0** | 2026-09-26 | First official version: all pages, Square ordering, AI chat, admin panel |

---

This is a **private** repository. All rights reserved.
Georgie's Alibi Monkey Bar.
