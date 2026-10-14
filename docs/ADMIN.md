# Admin panel

The admin panel is where the team sees what guests sent the website and controls
what the website shows. It lives at **`/admin/`** (locally
<http://localhost:3456/admin/>) and works on phones and tablets, so the kitchen
can run orders from a tablet.

- [Signing in](#signing-in)
- [What's in it](#whats-in-it)
- [Events: adding and publishing](#events-adding-and-publishing)
- [Where everything is saved](#where-everything-is-saved)
- [Security](#security)
- [For developers](#for-developers)

## Signing in

Set a long password in `api/config.php`:

```php
'admin_password' => 'a long password only the team knows',
```

Open `/admin/` and sign in. With no password set, the panel is switched off. (The
older setting name `chat_admin_password` still works.) You are signed out after 12
hours without a click, or with **Sign out** in the sidebar. More than ten sign-in
attempts in 15 minutes lock sign-in for a while, to stop password guessing.

The old address `api/chats.php` still works: it forwards to the new panel.

## What's in it

| Section | What you can do |
| --- | --- |
| **Dashboard** | See what needs attention now (new orders, reservation requests, unread messages), orders and chats per day for two weeks, whether pickup, delivery and Happy Hour are on right now, and setup checks (email alerts, the chat assistant's daily limit, HTTPS, password strength). |
| **Orders** | Orders placed on the website (cash on delivery). Tap **Start**, **Mark ready**, then **Picked up** / **Delivered** to move an order along, or open it to add a note, print a ticket, or cancel. Filter by status and date, search by name, phone or item, download a spreadsheet. |
| **Reservations** | Table requests from the reservation form. **Confirm** or **Decline**, and use the ready-made email buttons to reply in your own mail program. The website only takes requests, so nothing is booked until you answer. |
| **Messages** | The contact-form inbox. Unread messages are bold; mark them replied or archived, reply by email, or delete spam. |
| **Subscribers** | Newsletter sign-ups, one row per address. Search, download, or remove someone who asks to leave. |
| **Conversations** | Every chat guests had with the assistant. Returning guests and reservation requests are flagged. Flag a chat for follow-up and add a note. |
| **Insights** | *Chat assistant:* what guests ask about, which foods and shows interest them, when they chat, private-party demand, and the questions the assistant couldn't answer. *Orders:* sales, average order, busiest days and times, best sellers. |
| **Knowledge** | Edit what the chat assistant knows about the bar (`data/chatbot-knowledge.md`). Each save keeps the previous text, so a mistake is one click to undo. The page also lists recent questions the assistant couldn't answer. |
| **Events** | The weekly lineup and one-night specials. See [below](#events-adding-and-publishing). |
| **Menu & food** | Turn items on and off, change prices, make an item pickup-only or delivery-only, add a delivery surcharge or longer prep time, edit descriptions and options, and add your own dishes. Changes are live as soon as you save. |
| **Happy Hour** | The Happy Hour menu, its start and end times, and the scrolling ticker. |
| **Store settings** | Business name, address and phone; pickup and delivery hours; delivery area (ZIP codes), fees and minimums; prep time. |
| **Activity log** | Who signed in and what they changed, newest first. |

The numbers next to **Orders**, **Reservations** and **Messages** in the sidebar
count what is waiting. They refresh every 30 seconds, the page title shows the
total, and a pop-up appears when something new arrives, so you can leave the
Orders page open on a tablet.

Statuses, notes and flags that the team adds are saved *beside* the guests'
records: an order, reservation or message itself is never edited. (The two things
you can remove are a contact message and a newsletter address.)

## Events: adding and publishing

**Events** manages what the public Events page and the home page slider show.

1. Open **Events** and press **Add event**. (The first time, the 26 events already
   on the Events page are read in, so you start from what is live.)
2. Fill in the **title**, **hosted by**, **description** and **tags**. Choose the
   **time**, and how often it happens:
   - **Every week**: tick one or more days. Several events can share a day at
     different times (or even the same time); they are listed in time order.
   - **One night only**: pick the date. It shows on that weekday's tab with a date
     badge, and disappears by itself once the night is over.
3. Add the **poster** (see the size guide below).
4. Press **Save & publish** to put it on the website now, or **Save draft** to
   keep working. Drafts and edits are not on the website until you publish: the
   **Events** page tells you when the website is behind ("You have changes that
   aren't on the website yet") and offers **Publish to website**.

Untick **Show this event on the website** to keep an event saved without showing
it. Use **Duplicate** to make a variation of an event (the copy starts paused).

### Poster size guide

| | |
| --- | --- |
| **Size** | **480 × 600 px**, portrait (4:5) |
| **File** | WebP, JPG or PNG, up to 1.5 MB |
| **Events page and home slider** | Shown as a **square from the bottom of the picture**, so the top 120 px is cut off and the time badge covers the top-left corner. Keep the artwork and the title in the lower part. |
| **Chat assistant** | Shows the whole picture, small. |

You don't have to prepare the picture: the editor on the form crops and resizes
any picture to 480 × 600 for you. Choose a picture (or drop it on the frame), drag
it to choose what shows, zoom to fit, and turn on **Show guides** to see the part
that is cut off. The **Preview** card shows the event exactly as visitors will see
it. (The resizing happens in the browser, so it works without any server image
library. The server then checks the file really is a WebP, JPG or PNG of exactly
480 × 600.)

### How publishing works

`events.html` is plain HTML that the day tabs, the home page slider and the chat
assistant all read. Publishing rewrites the tabs, day panels and "week at a
glance" between the two `admin:events` comment markers in that file, using exactly
the markup the page already uses, and touches nothing else on the page.

- **Safe by design.** The previous page is copied to `api/data/events-backups/`
  before every publish (the last 15 are kept; **Earlier copies of the Events page**
  restores one). A publish is refused if an event has no poster file, or if the new
  page would not have seven days and every event's card.
- **If the page was edited by hand** (or replaced by an upload), the Events page
  warns "The Events page was edited outside this admin". **Load the page's events
  here** brings those edits into the admin; **Publish anyway** replaces them.
- **Keep the markers.** The page needs both pairs of comments,
  `<!-- admin:events:lineup --> … <!-- /admin:events:lineup -->` and
  `<!-- admin:events:glance --> … <!-- /admin:events:glance -->`. Without them,
  publishing is switched off and the Events page says so.
- **Posters** are saved in `images/events/` with a safe file name (title plus a
  random suffix). Deleting an event keeps its picture.
- **One-night events** carry their date in the page (`data-date`). The Events page
  hides one after its night (nights run until 4 AM), the home slider shows it only on
  its own date, and the chat assistant calls it "one night only".

## Where everything is saved

Guest records and the team's changes to them live in the **MySQL database**
(tables from `api/setup.sql`, login in `api/config.php`). Working files are in
`api/data/` (private, never committed to git), and a publish or an edit writes
into the website itself.

| What | Where |
| --- | --- |
| Orders, with their status, notes and kitchen times | `orders` table |
| Reservations, contact messages, newsletter | `reservations`, `contacts`, `subscribers` tables |
| Chats, with follow-up flags and notes | `chat_sessions` and `chat_messages` tables |
| "Call a waiter" requests | `waiter_calls` table |
| Activity log | `admin_activity` table |
| Guest records saved while the database was down | `api/data/unsaved/` (the dashboard offers **Move into database**) |
| Events working copy | `api/data/events.json` |
| Earlier copies of the Events page | `api/data/events-backups/` |
| Earlier chat knowledge | `api/data/knowledge-history/` |
| Menu overrides, store settings, Happy Hour, KDS settings | `settings` table |
| Sign-in sessions | `api/data/sessions/` |
| Chat knowledge | `data/chatbot-knowledge.md` |
| The public Events page and its posters | `events.html` and `images/events/` |

When you upload a new version of the site to the host, **don't overwrite
`api/data/`, `events.html` or `images/events/`** with the copies on your
computer, and never import your own database over the live one: the live ones
hold the team's changes. The dashboard's **Setup checks** show whether the
database is connected.

## Security

- Everything needs the password, except the sign-in page itself. The session
  cookie is `HttpOnly` and `SameSite=Lax` (and `Secure` on HTTPS).
- Every form carries a CSRF token, and every save checks it.
- Guests' text (names, messages, chat) is always HTML-escaped. Spreadsheet
  downloads neutralise anything that looks like a formula.
- The pages send `Content-Security-Policy` (no outside scripts, no framing),
  `X-Robots-Tag: noindex`, and are never cached. `robots.txt` also disallows
  `/admin/`.
- `admin/.htaccess` blocks `admin/inc/`, and each of those files refuses to run
  unless the panel loaded it. On **Nginx**, add a rule that denies `/admin/inc/`.
- Uploaded posters must be real WebP, JPG or PNG files of exactly 480 × 600 px.
- Use HTTPS on the live site (see [DEPLOYMENT.md](DEPLOYMENT.md)) so the
  password isn't sent in the clear.

## For developers

```
admin/
├── index.php         sign-in, routing (?page=name), dispatch
├── .htaccess         blocks inc/, sets DirectoryIndex and noindex
├── assets/
│   ├── admin.css     the whole design (dark, gold accent; tokens at the top)
│   ├── admin.js      tooltips, confirm prompts, filters, live badges
│   └── events.js     event form: how-often switch, tags, preview, poster editor
└── inc/
    ├── bootstrap.php loads the shared api/ libraries and the files below
    ├── auth.php      session, CSRF, sign-in page, security headers
    ├── layout.php    sidebar navigation (ADMIN_NAV) and the page shell
    ├── ui.php        escaping, formatting, icons, badges, tabs, paging, charts
    ├── store.php     reading records, statuses/notes, the activity log
    ├── events.php    reading and writing the Events page, posters, backups
    └── pages/        one file per section, each defining admin_page_<name>()
```

- **Add a section:** create `admin/inc/pages/<name>.php` with a function
  `admin_page_<name>()` (dashes become underscores), add the name to
  `ADMIN_PAGES` in `bootstrap.php` and an entry to `ADMIN_NAV` in `layout.php`.
  End a page with `admin_render($title, $bodyHtml, $activePage)`.
- **Records:** the website's own files are read in `store.php`. Statuses and notes
  go through `meta_set()` / `meta_update()` (locked, so two people saving at once
  don't lose each other's changes). Log changes with `admin_log()`.
- **Forms:** POST, then redirect (`redirect(admin_url(...))`) with a message
  (`flash()`); check `require_csrf()` first.
- **PHP:** written for PHP 7.4+ like the rest of the site (no `match`, union types
  or `str_contains`).
- The colours are checked for WCAG AA contrast, and the form-field border is at
  least 3:1 against the cards.
