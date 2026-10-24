# Installing a test copy on InfinityFree (free shared hosting)

This puts a working copy of Bono Loan Manager online for **testing**. It uses a single SQLite file, so nothing besides PHP is needed.
Use it with test data only: a free host gives no service guarantee and your data sits on shared storage.

## 1. Create the hosting account
1. Sign up at InfinityFree and create a hosting account. Choose a free sub-domain (for example `bonotest.rf.gd`) or connect your own domain.
2. Wait for the account to become active, then open its **Control Panel**.
3. In the control panel choose the highest PHP version offered (8.1 or newer) under *Select PHP Version* / *PHP Config* if the option exists.
4. Request the **free SSL certificate** (Client Area > *Free SSL Certificates*) for your address and install it when it is issued. The application insists on `https://` when `APP_ENV=production`.

## 2. Upload the files
1. Use an FTP program (FileZilla) with the FTP details shown in the control panel, or the online File Manager.
2. Upload **everything inside the package** (the folders `app`, `assets`, `database`, `deploy`, `docs`, `storage` and the files `index.php`, `.htaccess`, `hostcheck.php`) into the **`htdocs`** folder. Keep the structure. Hidden files such as `.htaccess` must be uploaded too (turn on "show hidden files").
3. Make sure these folders exist and are writable (permissions 755, or 775 if asked): `storage/db`, `storage/uploads`, `storage/backups`, `storage/logs`.

## 3. Check the host
Open `https://YOUR-SITE/hostcheck.php`. Every red **MISSING** item must be fixed (the main ones are PHP 8.1+, `pdo_sqlite`, `mbstring`, `iconv`, `openssl`, and writable `storage` folders).
If `pdo_sqlite` is missing, this host cannot run the system: use a host with SQLite or PostgreSQL support instead.
**Delete `hostcheck.php`** when it is green.

## 4. Configure
Create a file called **`env.php`** in `htdocs` with the content of `deploy/infinityfree/env.example.php.txt`, then change:
* `APP_KEY`: a long random string of your own (40+ characters). Keep a copy; changing it later makes saved MFA secrets unreadable.
* `SQLITE_PATH`: an unguessable file name such as `storage/db/bono_k39fj2q8x.sqlite`.
* `APP_URL`: your site address.
If the site loops on redirects after enabling HTTPS, add `'TRUST_PROXY' => '1',`. (A `.env` file works too, but `env.php` is safer on shared hosting.)

## 5. First start
1. Open `https://YOUR-SITE/`. A fresh installation shows a plain sign-in page with nothing configured and a single button, **Set up a new organisation**. Click it and fill in the organisation, its first branch and the System Administrator (use a strong password). Afterwards the sign-in page carries your organisation's name and logo.
2. Sign in and set up multi-factor authentication when asked (an authenticator app on your phone).
3. Follow `docs/UAT_GUIDE.md` section 1 (institution profile, branches, users, policy, opening balances, start the business day).

## 6. Check the protection
In the browser, `https://YOUR-SITE/storage/db/`, `https://YOUR-SITE/app/`, `https://YOUR-SITE/database/migrations/001_initial.sql` and `https://YOUR-SITE/.env` must answer **403 Forbidden** (or 404). If you can see anything there, stop and fix the `.htaccess` upload before entering any data.

## What to expect on a free host
* **E-mail** is normally blocked: invitations and password-reset messages are written to `storage/logs/mail.log` (open it by FTP) instead of being sent. Give people their temporary password in person.
* **Time limits** are short (about 10 seconds per request); End of Day already runs in small steps for this reason. The first use of the address drop-downs loads the national list once (a few seconds).
* **No scheduled jobs**: take backups yourself from *Backup & Recovery* and download them; do not rely on the host for backups.
* **Hit limits and inactivity**: free accounts can be suspended if idle or over the daily hit limit; keep a downloaded backup.
* **Privacy**: do not load real client records here.

## Troubleshooting
| Symptom | What to do |
|---------|------------|
| Blank page or "500" | Open `storage/logs/php-error.log` by FTP; check the PHP version (8.1+) and that `env.php` has no typing errors |
| "APP_KEY is not set" | Add `'APP_KEY' => '...'` to `env.php` |
| Redirect loop | Add `'TRUST_PROXY' => '1'` to `env.php` |
| "Session expired" at every click | HTTPS must be active (the session cookie is secure-only in production); or temporarily set `APP_ENV=testing` for a plain-http trial |
| Cannot write / "database is locked" | Check permissions on `storage/db`; try again in a minute |
| Pictures or styles missing | `assets/` and `.htaccess` were not uploaded |
