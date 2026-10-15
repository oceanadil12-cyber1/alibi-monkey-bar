# Putting the Alibi Monkey Bar website live (hosting.com reseller)

About 20–30 minutes. Everything here is done in WHM/cPanel; no command line needed.

## What's in this folder

| Item | Use it for |
| --- | --- |
| `alibi-website-upload.zip` | The whole website. Upload it to `public_html` with cPanel File Manager and **Extract** |
| `public_html/` | The same files unzipped, if you prefer FTP/SFTP: upload the **contents** of this folder into `public_html` |
| `HOW-TO-GO-LIVE.md` | This guide |
| `VERSION.txt` | Which version and commit the package was built from |

Nothing secret is included. You create `api/config.php` on the server (step 5).

## What the site needs

- A cPanel account for the domain, with the site at the **domain root** (`public_html`), not in a subfolder
- **PHP 8.1 or newer** (8.2 recommended) with `pdo_mysql`, `mbstring`, `curl`, `dom`/`xml` and `json`, which are normally on by default
- **MySQL 8.0.13+ or MariaDB 10.2+** (every current cPanel server has this)
- Apache or LiteSpeed reading `.htaccess` files (the hosting.com default)
- An SSL certificate (free AutoSSL in cPanel)

## Steps

1. **cPanel account.** In WHM → *Create a New Account* for the domain (or open the
   existing one) and point the domain's DNS at the hosting.com server.

2. **PHP version.** cPanel → *MultiPHP Manager* (or *Select PHP Version*): choose
   **PHP 8.2** for the domain. In *Select PHP Version → Extensions*, make sure
   `pdo_mysql` (or `nd_pdo_mysql`), `mbstring`, `curl`, `dom` and `xml` are ticked.

3. **Upload.** cPanel → *File Manager* → `public_html` → *Upload*
   `alibi-website-upload.zip` → right-click it → *Extract* → then delete the zip.
   Click *Settings* → *Show Hidden Files* and check that `.htaccess` is there (in
   `public_html`, `api/`, `admin/`, `kds/` and `dine-in/`). Remove any default
   `index.html`/`default.html` the host put there before, if it wasn't replaced.

4. **Database.** cPanel → *MySQL Databases*:
   - *Create New Database*, e.g. `alibi` (cPanel makes it `cpuser_alibi`)
   - *Add New User* with a generated password (save it)
   - *Add User To Database* → tick **ALL PRIVILEGES**

   You don't need to import anything: the site creates its tables on the first
   visit. (Importing `api/setup.sql` in phpMyAdmin also works and is safe to repeat.)

5. **Settings file.** File Manager → `public_html/api/` → copy
   `config.example.php` to **`config.php`** → *Edit*. Fill in section 1 only:
   - `db_name`, `db_user`, `db_pass` from step 4 (full names with the `cpuser_` prefix; keep `db_host` as `localhost`)
   - `admin_password`: a long password for the team (the admin panel and kitchen display use it)
   - `notify_email`: where new orders, messages and reservations are emailed
   - `from_email`: an address on the site's domain, e.g. `website@DOMAIN`
   - `chat_provider` and its API key (ask the owner for the chat assistant's keys)

6. **Check it.** Open `https://DOMAIN/admin/`, sign in, and look at
   *Dashboard → Setup checks*: **Database connected** with the server version,
   and no "Database tables missing" line.

7. **HTTPS.** cPanel → *SSL/TLS Status* → *Run AutoSSL*. When `https://DOMAIN`
   works, edit `public_html/.htaccess` and remove the `#` in front of the five
   lines under `HTTPS` so every visit is redirected to https.

8. **Email.** cPanel → *Email Accounts*: create the `from_email` mailbox. In
   *Email Deliverability*, repair SPF/DKIM if it shows problems, so the
   restaurant's notifications don't go to spam.

## Test before handing over

- [ ] Home page, Menu, Events, Performers and Contact pages load on `https://` and on a phone
- [ ] Menu → add dishes to the cart → place a **pickup** order (cash): you get an order number
- [ ] The order shows in **Admin → Orders** and the email arrives at `notify_email`
- [ ] **Kitchen display** `https://DOMAIN/kds/` (admin password): the order appears; *Start* and *Complete* work
- [ ] **Table ordering** `https://DOMAIN/dine-in/table/1`: order something; it reaches the KDS. *Call waiter* shows in **Admin → Waiter assistance**
- [ ] Contact form, reservation request and newsletter sign-up show in Admin → Messages / Reservations / Subscribers
- [ ] The "Ask us" chat answers a question, and the chat shows in **Admin → Conversations**
- [ ] These are **blocked** (403 or 404): `/api/config.php`, `/api/data/`, `/api/setup.sql`, `/admin/inc/store.php`, `/data/chatbot-knowledge.md`
- [ ] A made-up address (e.g. `/nothing-here`) shows the branded 404 page

Afterwards mark your test orders **Cancelled** in Admin → Orders (or delete the rows in phpMyAdmin).

## After launch: never overwrite these

`api/config.php`, the live database, `api/data/`, `events.html` and
`images/events/` hold the restaurant's own changes (the team publishes events
from the admin). For later updates, upload only the files that changed. Back up
the database regularly: cPanel → *Backup* → *Download a MySQL Database Backup*.

## If something doesn't work

| What you see | Fix |
| --- | --- |
| "500 Internal Server Error" right after upload | cPanel → *Errors* shows why. Usually the PHP version is too old (step 2) |
| Admin says "The database can't be reached" | Wrong `db_*` in `config.php` (use the full `cpuser_` names), or the user isn't added to the database (step 4) |
| Dashboard says "Database tables missing" | The database user lacks CREATE rights: give it ALL PRIVILEGES, or import `api/setup.sql` in phpMyAdmin |
| Chat replies "please call us" | `chat_provider` or its API key is missing or wrong in `config.php` |
| Emails don't arrive | `from_email` must be on the site's domain; check *Email Deliverability* and the spam folder |
| `/admin/` or `/kds/` shows a file list or 404 | The hidden `.htaccess` files weren't uploaded (step 3) |
| Table links `/dine-in/table/5` show 404 | `.htaccess` in `public_html` is missing or `mod_rewrite` is off |

More detail for developers: `docs/DEPLOYMENT.md` in the project repository.
