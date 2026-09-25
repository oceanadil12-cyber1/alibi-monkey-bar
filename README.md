# Alibi Monkey Bar — Website

The official website for **Georgie's Alibi Monkey Bar**, a restaurant, bar and
entertainment venue at 2266 Wilton Drive, Wilton Manors, FL 33305
(phone (954) 565-2526).

It is a fast, hand-built site: plain HTML, CSS and JavaScript, with no build
step and no framework. A small PHP backend adds the **live Square menu**,
**online ordering with card payment**, and the **contact, reservation and
newsletter forms**.

> **Private repository.** This is client work. Do not make the repository
> public, and never commit `api/config.php`, which holds the Square access token.

---

## How to display the website

Pick the option that fits what you need.

| Option | What works | Needs |
| --- | --- | --- |
| **A. Double-click `Start Website.bat`** | Everything | Windows + XAMPP (or Node.js for a basic preview) |
| **B. PHP built-in server** | Everything: live menu, ordering, forms | PHP 7.4+ |
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
- **Forms** save submissions to `api/data/*.csv`. Email notifications need a
  mail server, so they normally only arrive on the live host.

### C. Static server with Node.js (design preview)

```bash
npx serve . -l 3456
```

Then open <http://localhost:3456>. All pages, images, events, sliders and the
gallery display normally. The menu falls back to `data/menu.json`, and forms and
checkout show a friendly "please call us" message, because they need PHP.

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

## Project structure

```
alibi-monkey-bar/
├── *.html                 Site pages (edit these directly)
├── css/style.css          All styling, including icons (inline SVG)
├── js/                    Page scripts (no libraries)
├── images/                WebP images: site/, events/, dancers/, awards, hero
├── fonts/                 Self-hosted Inter + Playfair Display (woff2)
├── data/menu.json         Menu snapshot, used if Square is unreachable
├── api/                   PHP backend (menu, checkout, forms)
│   └── config.example.php Template for the private api/config.php
├── .htaccess              Apache: security, caching, clean URLs, 404 page
├── robots.txt
├── ORDERING-SETUP.md      Square ordering setup guide for the owner
├── Start Website.bat      Windows one-click launcher
└── docs/                  Developer documentation
```

## Documentation

| Document | Read it when you want to… |
| --- | --- |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | understand how the site is built, page by page and script by script |
| [docs/CONTENT-GUIDE.md](docs/CONTENT-GUIDE.md) | change events, dancers, photos, text, hours or the menu |
| [docs/API.md](docs/API.md) | work on the PHP endpoints (menu, checkout, forms) |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | publish or update the live site |
| [ORDERING-SETUP.md](ORDERING-SETUP.md) | connect Square payments (restaurant owner's steps) |

## Tech at a glance

- **Front end:** HTML5, one CSS file, vanilla JavaScript. No build tools or npm packages.
- **Back end:** PHP 7.4+ with `curl` and `mbstring`. No database: data comes
  from Square, and submissions are saved to CSV files.
- **Third-party services:** Square (menu, orders, payments, customer directory)
  and Elfsight (Google reviews and Instagram widgets).
- **Performance:** WebP images in 640/1024/1600 widths, lazy loading,
  self-hosted fonts, long-lived caching with `?v=` cache-busting.

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

© Georgie's Alibi Monkey Bar. All rights reserved. Private client project.
