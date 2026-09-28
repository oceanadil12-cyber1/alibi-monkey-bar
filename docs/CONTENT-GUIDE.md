# Content Guide

How to make common updates. All pages are plain HTML, so open the file in any
code editor (VS Code recommended), edit, save, and refresh the browser.

Preview your changes locally first (see the README, "How to display the website").

## Events (weekly lineup)

**Edit only `events.html`.** The home page's "Upcoming Events" slider reads
this page automatically, so you never edit the home page for events.

Each day is a `<div class="ev-day" data-day="mon">` block. Each event inside it
is one `<article class="ev-card">`:

```html
<article class="ev-card">
  <div class="ev-poster">
    <img src="images/events/my-new-show.webp" alt="My New Show poster"
         width="480" height="600" loading="lazy" decoding="async">
    <span class="ev-time">9:00 PM</span>
  </div>
  <div class="ev-body">
    <h4>My New Show</h4>
    <p class="ev-host">Hosted by Someone</p>
    <p class="ev-desc">One-line description of the night.</p>
    <ul class="ev-tags"><li>Drag Show</li><li>No Cover</li></ul>
  </div>
</article>
```

- **Add an event:** copy a card into the right day and keep the cards in time order.
- **Remove an event:** delete its whole `<article>…</article>`.
- **Time format:** use `9:00 PM` style. The home slider uses it to work out what's next.
- **Tab counts:** update the "N events" text on that day's tab button near the
  top of the lineup (`<span class="ev-tab-count">`).
- **Poster:** save a 480×600 WebP in `images/events/`.

## Dancers

In `dancers.html`, each dancer is a card with a photo in `images/dancers/`
(600×600 WebP) and a name. Copy a card to add one, delete it to remove one,
and update the `alt` text with the dancer's name.

## Photos

1. Convert the photo to **WebP**. <https://squoosh.app> is free and works in the browser.
2. For the large site photos, export three widths, `-640`, `-1024` and `-1600`,
   and follow the naming in `images/site/`.
3. Replace the file with the same name, or add a new file and update the `src`
   or `srcset` in the page.
4. Keep the `width` and `height` attributes matching the image's proportions.

## Text, phone, address and hours

Text is edited directly in each HTML file. The **header and footer are
repeated on every page**, so a change to the phone number, address, hours or
social links has to be made in all 10 pages. Search the whole folder to find
every copy (in VS Code: Ctrl+Shift+F).

## Menu and prices

**Don't edit the menu in the website.** It comes live from the restaurant's
**Square** account. Change items, prices, options, sold-out status, pickup
hours or delivery settings in Square, and the site updates within 15 minutes.

`data/menu.json` is only a backup snapshot, used when Square can't be reached.
To refresh it on a machine with PHP:

```bash
php api/menu.php --snapshot
```

## Forms

Contact, reservation and newsletter forms are `<form data-endpoint="api/…">`
elements, sent by `main.js`. Submissions are emailed to `notify_email` in
`api/config.php` and saved in `api/data/*.csv` on the server. You can download
the CSVs from the host's file manager.

## Chat assistant

The "Ask us" chat answers guests from:

| What | Where it comes from | How to change it |
| --- | --- | --- |
| Hours, policies, reservations, private events, FAQs | `data/chatbot-knowledge.md` | Edit the file (plain text) |
| Weekly show lineup | `events.html` | Edit the lineup as usual |
| Happy Hour and drinks | `menu.html` | Edit the page as usual |
| Food menu, prices, pickup/delivery | Square | Change it in Square |

Changes take effect on the next chat message. To see exactly what the
assistant knows, run `php api/chat.php --prompt`. To try a question without the
website, run `php api/chat.php "Are you open now?"`.

If it gives a wrong or vague answer, add the fact to `chatbot-knowledge.md` in
plain words.

**Chat history:** open `https://YOUR-DOMAIN/api/chats.php` and sign in with
`chat_admin_password` from `api/config.php`. You can read every conversation,
see which ones ordered food, and download a spreadsheet. Chats are anonymous
and are deleted automatically after 120 days (`chat_log_days`). The chat
window doesn't mention this, so cover chat storage in the site's privacy
policy.

## Google reviews and Instagram

These are **Elfsight** widgets (`<div class="elfsight-app-…">`). Their content
and layout are managed in the Elfsight dashboard, not in this code.

## Cache busting

The server tells browsers to keep CSS and JS for a month. Pages link to them
with a version tag:

```html
<link rel="stylesheet" href="css/style.css?v=20260925">
<script src="js/main.js?v=20260925"></script>
```

**Whenever you change `css/style.css` or a `js/` file, change the `?v=` value
to today's date in every page**, so visitors get the new version immediately.
A find-and-replace across the folder does it in one step.

## Before publishing a change

- [ ] Checked the page on desktop and on a phone-width window
- [ ] Bumped `?v=` if CSS or JS changed
- [ ] New images are WebP and have `width`/`height`/`alt`
- [ ] Committed to git with a clear message
