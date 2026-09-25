# Deployment

The site is built for a standard **Apache / cPanel host with PHP 7.4+**, which
is how most shared hosting is set up. The `.htaccess` files handle security,
caching, clean URLs and the 404 page.

## What to upload

Upload the **contents** of this repository to the web root (usually
`public_html/`), **except**:

| Don't upload | Why |
| --- | --- |
| `.git/` | Repository history. It's also blocked by `.htaccess` as a safety net |
| `docs/`, `README.md` | Developer notes. `.md` files are blocked anyway |
| `Start Website.bat`, `.gitignore` | Only used on your own computer |

Must upload: all `*.html`, `css/`, `js/`, `images/`, `fonts/`, `data/`, `api/`,
`.htaccess`, `api/.htaccess` and `robots.txt`.

> Dot-files like `.htaccess` are hidden by default in some FTP programs and in
> the cPanel File Manager. Turn on "show hidden files" and make sure both get uploaded.

## First-time setup

1. **Upload** the files as above (cPanel File Manager → Upload, or FTP/SFTP).
2. **Create `api/config.php`** on the server by copying `config.example.php`,
   then fill in the Square keys, `notify_email` and `from_email`. The full
   walkthrough is in [ORDERING-SETUP.md](../ORDERING-SETUP.md).
3. **Test the ordering** with `sandbox` keys and the test card, then switch to
   `production`.
4. **Enable HTTPS.** Once the SSL certificate is active (usually free with
   AutoSSL or Let's Encrypt in cPanel), uncomment the five HTTPS lines in the
   root `.htaccess`.
5. **robots.txt**: once the site is live, add the `Sitemap:` line with the real
   domain if you create a sitemap.
6. **Apple Pay / Google Pay** (optional): register the domain in the Square
   Developer Dashboard.

## Updating the live site

1. Make and preview the change locally.
2. If CSS or JS changed, bump `?v=` in the pages (see the Content Guide).
3. Commit to git: `git add -A && git commit -m "Describe the change" && git push`
4. Upload **only the changed files** to the server, overwriting the old ones.

**Never overwrite on the server:** `api/config.php` (live keys), `api/data/`
(customer submissions) or `api/cache/`.

## Checklist after deploying

- [ ] Home page loads over `https://` with no browser warnings
- [ ] Menu page shows the menu and the cart works
- [ ] A test order (sandbox) goes through and shows in the Square dashboard
- [ ] Contact form sends and the email arrives at `notify_email`
- [ ] `https://DOMAIN/api/config.php` is **blocked** (403), not downloadable
- [ ] `https://DOMAIN/api/data/` is **blocked** (403)
- [ ] A made-up URL shows the branded 404 page
- [ ] The site looks right on a phone

## Other hosting

- **Nginx or hosts without `.htaccess`:** recreate the rules yourself. Deny
  `api/config.php`, `api/lib.php`, `api/cache/`, `api/data/` and `/.git`, and set
  `data_dir` in `config.php` to a folder outside the web root.
- **Static-only hosts (Netlify Drop, GitHub Pages):** the pages display, but
  the live menu, ordering and forms need PHP and won't work. That's fine for a
  design preview only. Don't use GitHub Pages on this private repository, because
  the published site would be public.
