# Going live on Hostinger

Two routes. **Route A (shared hosting, SQLite)** is the quickest and is what this package is built for. **Route B (VPS, PostgreSQL)** is the stronger choice once you have many users or several organisations.

---
## Route A: Hostinger web hosting (Premium / Business / Cloud) with SQLite

### 1. Before you buy / start
* Plan: Premium or Business web hosting (Business adds daily backups). Pick the data-centre closest to your users.
* Add your domain (buy one at Hostinger or point an existing one to Hostinger's name servers).
* **Check SQLite first** (2 minutes): upload `hostcheck.php` (step 4) before you rely on the plan. If it reports `pdo_sqlite` MISSING, look for it under **hPanel > Advanced > PHP Configuration > PHP extensions** and enable it; if it is not offered, use Route B.

### 2. Prepare the website in hPanel
1. **Websites > Manage** your domain.
2. **Advanced > PHP Configuration**: choose **PHP 8.2** (or newer). On the *PHP extensions* tab make sure these are ticked: `pdo_sqlite` (and `sqlite3`), `mbstring`, `iconv`, `openssl`, `zlib`, `fileinfo`, `ctype`, `json`. On the *PHP options* tab set `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 16M, `post_max_size` 20M.
3. **Security > SSL**: make sure the free SSL certificate is **active** for the domain, then switch on **Force HTTPS**. The application insists on HTTPS in production.

### 3. Upload the files
1. Unzip `bono-loan-manager-infinityfree.zip` on your computer (the same package works; the name is historical).
2. In hPanel open **Files > File Manager** (or use FTP / the Git integration). Go to `public_html` (the folder may sit under `domains/your-domain/public_html`).
3. Delete Hostinger's default `index.html`/`default.php` there.
4. Upload everything inside the unzipped folder into `public_html`: `app`, `assets`, `database`, `deploy`, `docs`, `storage`, `index.php`, `.htaccess`, `hostcheck.php`. (Easiest: upload the **zip** itself into `public_html`, right-click > **Extract**, then delete the zip.) Hidden files such as `.htaccess` must be there: switch on "Show hidden files".

### 4. Check the host
Open `https://your-domain/hostcheck.php`. Every red **MISSING** must be cleared. When all green, **delete `hostcheck.php`**.

### 5. Keep the data outside the website folder (recommended on Hostinger)
Hostinger lets you create folders **above** `public_html`, which the internet can never reach. In File Manager create, next to `public_html`, a folder called `bono-data` with three sub-folders: `db`, `uploads`, `backups`. (The full path looks like `/home/u123456789/domains/your-domain/bono-data`; the File Manager path bar shows your exact one.)

### 6. Configure
Create `env.php` inside `public_html` from `deploy/hostinger/env.example.php.txt` and change:
* `APP_KEY` (40+ random characters, keep a copy in a safe place; losing it makes saved two-step-login secrets unreadable),
* the three data paths to your `bono-data` folders,
* `APP_URL` to your https address.

### 7. First start
1. Open `https://your-domain/`. A fresh installation shows a **plain sign-in page** with a single button: **Set up a new organisation**.
2. Click it, enter the organisation, first branch and System Administrator (strong password). The sign-in page then carries your organisation's name and logo.
3. Sign in and set up two-step verification (authenticator app) when asked.

### 8. Check the protection
These addresses must answer **403 or 404**: `/app/`, `/database/migrations/001_initial.sql`, `/storage/`, `/env.php` (blank page is fine), `/.env`. If you can see files, fix `.htaccess` before entering data.

### 9. E-mail (invitations and password resets)
Create a mailbox in hPanel (**Emails > Email Accounts**, e.g. `noreply@your-domain`). In the application: **Settings > Email**: server `smtp.hostinger.com`, port `465` with SSL (or `587` with STARTTLS), username = the full mailbox address, the mailbox password, and "Send emails from" the same address. Use the *Send a test* option to check.

### 10. Go-live set-up (System Administrator)
Follow `docs/UAT_GUIDE.md` section 1: institution profile (address, phones, logo, colour), branches, users and roles (Administration > Loan Process Roles), lending policy (rates, fees, penalties **only when management confirms**, approval limits, workflow rules), opening balances for safes/banks/mobile money, then **start the business day** for each branch. Add counties/villages missing from the national lists on *Administration > Uganda Locations*.

### 11. Backups (do not skip)
* The system backs up before End of Day and on a schedule when someone signs in (Backup & Recovery shows them). They are stored in `bono-data/backups` on the **same server**, so also **download** one regularly (weekly at least) to your own computer or cloud drive. Hostinger's own backups (Business plan) are a second layer, not the first.
* Test a restore once on a copy before going live.

### 12. Updating later
Upload the new files over the old ones (never over `env.php` or `bono-data`). Database changes apply automatically the first time the site is opened. Take a backup first.

### Troubleshooting
| Symptom | What to do |
|---------|------------|
| 500 error / blank page | Open `bono-data/..` or `storage/logs/php-error.log`; check PHP 8.2 and `env.php` syntax |
| "APP_KEY is not set" | Add `'APP_KEY' => '...'` |
| Redirect loop after Force HTTPS | Add `'TRUST_PROXY' => '1'` to `env.php` |
| Cannot write / database locked | Folder permissions 755 (775 if asked) on `bono-data/*`; try again in a minute |
| E-mails not arriving | Re-check the mailbox, port and password; look in *Settings > Email* for the test result |

---
## Route B: Hostinger VPS with PostgreSQL (recommended for growth)
Use this when you expect many simultaneous users, several organisations, or want the strongest data protection and performance.
1. Order a **KVM VPS** (Ubuntu 22.04/24.04, 2 vCPU / 4 GB is a comfortable start). Point your domain's A record to the VPS address.
2. Install Docker and Docker Compose, upload the project (the package plus `Dockerfile`, `docker-compose.yml`, `deploy/` from the repository), copy `.env.example` to `.env` and fill in the database password, `APP_KEY`, `DOMAIN`. Caddy in the compose file obtains the HTTPS certificate automatically.
3. `docker compose up -d`, then open the domain: the same plain first-run sign-in page appears.
4. Switch on the VPS **weekly/daily snapshots** in hPanel and schedule `pg_dump` copies off the server. Details are in `docs/CLOUD_DEPLOYMENT.md`. These Docker steps have not been tested on a live server yet, so rehearse them on a spare VPS first.

## Which route?
* Up to roughly 10-15 staff using it at the same time, one organisation: **Route A** is fine.
* More users, several organisations, or you want PostgreSQL's concurrency and backup tooling: **Route B**.
