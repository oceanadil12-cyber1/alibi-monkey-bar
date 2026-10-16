# Putting the Alibi Monkey Bar website live

About 10 minutes in cPanel, and no files to edit. The owner's settings (admin
password, notification email, chat assistant keys) are already inside the package.
The only thing the site can't do for itself is create its MySQL database, because
that needs the hosting login.

## What's in this folder

| Item | Use it for |
| --- | --- |
| `alibi-website-upload.zip` | The whole website. Upload it in cPanel and **Extract** it |
| `HOW-TO-GO-LIVE.md` | This guide |
| `VERSION.txt` | Which version the package was built from |

**Private:** the ZIP holds the admin password and the chat API keys (in
`api/site-settings.php`, which the site blocks from the web). Don't pass it on.

## Steps

1. **Upload.** cPanel → *File Manager* → open the domain's folder (`public_html`
   for the main domain) → *Upload* `alibi-website-upload.zip` → right-click it →
   *Extract* → delete the zip. `index.html`, `admin/` and `api/` must sit directly
   in that folder, not inside another folder. (The site also works from a
   subfolder, e.g. for a preview, but the live site belongs at the domain root.)

2. **Database.** cPanel → *MySQL Databases*:
   - *Create New Database*, e.g. `alibi` (cPanel names it `cpuser_alibi`)
   - *Add New User* with a password
   - *Add User To Database* → tick **ALL PRIVILEGES**

3. **Connect.** Open `https://DOMAIN/admin/`, sign in with the admin password
   (the owner gives it to you), and type the database name, user and password into
   the **Connect the database** screen. The site checks them, creates its tables and
   saves the details itself. That's it: the website is live.

## Quick check

- [ ] Home, Menu, Events and Contact pages load, also on a phone
- [ ] Menu → add a dish → place a **pickup** order: it shows in **Admin → Orders** and on the **kitchen display** (`/kds/`, same password)
- [ ] `https://DOMAIN/dine-in/table/1` → order something: it reaches the kitchen display
- [ ] The "Ask us" chat answers a question
- [ ] `/api/config.php` and `/api/site-settings.php` show **403 Forbidden**

Afterwards mark the test orders **Cancelled** in Admin → Orders.

## Hosting settings worth a look

These belong to the hosting account, not the website, and are usually already right:

- **HTTPS:** AutoSSL normally issues the certificate by itself. To send every visit
  to https, turn on cPanel → *Domains* → **Force HTTPS Redirect**.
- **PHP 8.1 or newer** (8.2 recommended): cPanel → *MultiPHP Manager*.
- **Email:** cPanel → *Email Deliverability* → *Repair* if it shows a problem, so
  order emails don't land in spam.

## Later updates: never overwrite these on the server

`api/config.php` (the database details), `api/data/`, `events.html`,
`performers.html`, `images/events/`, `images/performers/` and
`data/chatbot-knowledge.md`. The team changes them from the admin panel. Back up
the database regularly: cPanel → *Backup* → *Download a MySQL Database Backup*.

## If something doesn't work

| What you see | Fix |
| --- | --- |
| "500 Internal Server Error" | PHP is too old: cPanel → *MultiPHP Manager* → PHP 8.2 |
| The Connect screen shows a red message | It says what's wrong (user, password, database name or privileges) |
| `/admin/` shows a file list or "Not Found" | The hidden `.htaccess` files are missing: File Manager → *Settings* → *Show Hidden Files*, then extract the ZIP again |
| `/dine-in/table/5` shows "Not Found" | Same as above: `.htaccess` missing, or the host has `mod_rewrite` off |
| Emails don't arrive | Check *Email Deliverability* and the spam folder |

More detail for developers: `docs/DEPLOYMENT.md` in the project repository.
