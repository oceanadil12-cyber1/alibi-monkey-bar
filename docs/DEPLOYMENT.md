# Deployment

The site is built for a standard **Apache / cPanel host with PHP 7.4+ and a MySQL
database** (MySQL 8.0.13+ or MariaDB 10.2+), which is how most shared hosting,
including a hosting.com reseller account, is set up. The `.htaccess` files handle
security, caching, clean URLs and the 404 page.

## What to upload

**Easiest:** double-click `Make Upload Package.bat`. It builds the folder
`Alibi Website - For Developer` next to the repository with exactly the files for
the server (as a folder and as `alibi-website-upload.zip`) plus the step-by-step
guide `HOW-TO-GO-LIVE.md` (the same text as [GO-LIVE.md](GO-LIVE.md)). Hand that
folder to whoever puts the site live. Nothing secret is in it.

Uploading by hand instead: upload the **contents** of this repository to the web
root (usually `public_html/`), **except**:

| Don't upload | Why |
| --- | --- |
| `.git/` | Repository history. It's also blocked by `.htaccess` as a safety net |
| `docs/`, `README.md` | Developer notes. `.md` files are blocked anyway |
| `Start Website.bat`, `Save to GitHub.bat`, `.gitignore`, `.gitattributes` | Only used on your own computer |

Must upload: all `*.html`, `css/`, `js/`, `images/`, `fonts/`, `data/`, `api/`,
`admin/`, `.htaccess`, `api/.htaccess`, `admin/.htaccess` and `robots.txt`.

> Dot-files like `.htaccess` are hidden by default in some FTP programs and in
> the cPanel File Manager. Turn on "show hidden files" and make sure both get uploaded.

## First-time setup

1. **Upload** the files as above (cPanel File Manager → Upload, or FTP/SFTP).
2. **Create the database.** On a hosting.com reseller account, first create the
   site's own cPanel account in WHM, then log in to that cPanel:
   1. *MySQL Databases* → create a database (cPanel names it like `cpuser_alibi`).
   2. On the same page, create a user with a long generated password, then
      *Add User to Database* with **All Privileges**.
   3. Nothing to import: the site creates its 10 tables on the first visit once
      `config.php` has the database login (step 3). If the user has no CREATE
      rights, *phpMyAdmin* → the database → *Import* → `api/setup.sql` does the same.
   4. On phpMyAdmin's home page, check *Server version* is MySQL 8.0.13+ or
      MariaDB 10.2+.
3. **Create `api/config.php`** on the server by copying `config.example.php`,
   then fill in `db_name`, `db_user` and `db_pass` from step 2 (keep `db_host` as
   `localhost`), plus the Square keys, `notify_email` and `from_email`. The full
   walkthrough is in [ORDERING-SETUP.md](../ORDERING-SETUP.md).
4. **Test the ordering** with `sandbox` keys and the test card, then switch to
   `production`.
5. **Enable HTTPS.** Once the SSL certificate is active (usually free with
   AutoSSL or Let's Encrypt in cPanel), uncomment the five HTTPS lines in the
   root `.htaccess`.
6. **robots.txt**: once the site is live, add the `Sitemap:` line with the real
   domain if you create a sitemap.
7. **Apple Pay / Google Pay** (optional): register the domain in the Square
   Developer Dashboard.
8. **Chat assistant**: see below.

**Backups:** cPanel → *Backup* → *Download a MySQL Database Backup* (or phpMyAdmin →
*Export*) saves every order, reservation, message and chat. Take one before each
update and now and then.

## Chat assistant on the live site

Shared hosting can't run an AI model itself, so the live site uses a cloud AI.

**Free option (Groq):** copy the chat settings from your local `api/config.php`
into the server's `api/config.php`: `chat_provider`, `chat_fallback`,
`groq_api_key` and `gemini_api_key`. No Composer or PHP 8.1 is needed. Use a
Groq account in the restaurant's name, and turn on Zero Data Retention in the
Groq console (Settings → Data Controls). The free plans allow a few questions
per minute across all the fallbacks. Check the chat history
(Admin → Conversations) now and then. If guests often hit the limit, switch to a paid plan.

**Paid option (Claude):**

1. The restaurant creates an account at <https://console.anthropic.com>,
   adds a card, buys some credit and **sets a monthly spend limit**. Then it
   creates an API key.
2. Check the host runs **PHP 8.1 or newer** (cPanel → *Select PHP Version*).
3. Install the Anthropic SDK: in cPanel's *Terminal*, `cd public_html/api`
   and run `composer require anthropic-ai/sdk`. Without Terminal, run
   the same command in `api/` on your computer and upload the `api/vendor/`
   folder it creates.
4. In `api/config.php`, set `'chat_provider' => 'claude'`, paste the key into
   `'anthropic_api_key'` and choose `'claude_model'`.
5. Ask the chat a few questions on the live site. Check the replies in the
   chat history (Admin → Conversations), which also shows the token counts behind the bill.

`chat_daily_limit` in `config.php` caps how many messages the site answers per
day (default 500), so spam can't run up the bill.

## Admin panel on the live site

Set a long `admin_password` (12+ characters) in the server's `api/config.php`,
then open `https://DOMAIN/admin/`. Keep the password private: the panel shows every
order, message and conversation, and can change the menu and the website. Turn on
HTTPS first (step 5 above) so the password isn't sent in the clear. The panel is
never indexed and needs no other setup; see [ADMIN.md](ADMIN.md).

Set `notify_email` and `from_email` too, so new orders and messages are emailed
as well as shown in the panel.

**Events** are published by the panel by rewriting part of `events.html` and saving
posters in `images/events/`, so those two must be writable by the website (they
are on ordinary cPanel hosting). **Never upload your own copy of `events.html` or
`images/events/` over the live ones** once the team is using the panel.

## Updating the live site

1. Make and preview the change locally.
2. If CSS or JS changed, bump `?v=` in the pages (see the Content Guide).
3. Commit to git: `git add -A && git commit -m "Describe the change" && git push`
4. Upload **only the changed files** to the server, overwriting the old ones.

**Never overwrite on the server:** `api/config.php` (live keys and the database
login), the live database (never import a local copy over it), `api/data/` (sign-ins
and what the admin panel saves), `api/cache/`, and `events.html` and
`images/events/` (the team publishes events there from the panel). Re-importing
`api/setup.sql` is safe: it only adds tables that are missing.

## Checklist after deploying

- [ ] Home page loads over `https://` with no browser warnings
- [ ] Admin → Dashboard → *Setup checks* shows **Database connected** with the MySQL/MariaDB version
- [ ] Menu page shows the menu and the cart works
- [ ] A test order (sandbox) goes through and shows in the Square dashboard
- [ ] Contact form sends and the email arrives at `notify_email`
- [ ] `https://DOMAIN/api/config.php` is **blocked** (403), not downloadable
- [ ] `https://DOMAIN/api/data/` and `https://DOMAIN/api/setup.sql` are **blocked** (403)
- [ ] `https://DOMAIN/admin/` shows the sign-in page, and `https://DOMAIN/admin/inc/store.php` is **blocked** (403)
- [ ] Signing in to the admin works, and a test event can be published and appears on the Events page
- [ ] The "Ask us" chat answers a question, and `https://DOMAIN/api/vendor/` and
      `https://DOMAIN/data/chatbot-knowledge.md` are **blocked**
- [ ] A made-up URL shows the branded 404 page
- [ ] The site looks right on a phone

## Other hosting

- **Nginx or hosts without `.htaccess`:** recreate the rules yourself. Deny
  `api/config.php`, `api/lib.php`, `api/cache/`, `api/data/`, `admin/inc/` and
  `/.git`, make `index.php` the index of `admin/`, and set `data_dir` in
  `config.php` to a folder outside the web root.
- **Static-only hosts (Netlify Drop, GitHub Pages):** the pages display, but
  the live menu, ordering and forms need PHP and won't work. That's fine for a
  design preview only. Don't use GitHub Pages on this private repository, because
  the published site would be public.
