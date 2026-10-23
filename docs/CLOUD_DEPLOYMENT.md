# Cloud deployment guide

Bono Loan Manager v3 is a cloud web application: browsers → **HTTPS** → app servers (PHP/Apache) → **managed PostgreSQL**, with private file storage and
separate backups. This guide covers three ways to host it, the security settings that must be on in production, backups / disaster recovery and monitoring.

```
 staff browsers ──HTTPS──► TLS proxy / load balancer ──► app container(s) ──► PostgreSQL (managed, TLS)
                                                          │                     └─ automated backups + PITR
                                                          ├─► private file storage (volume or S3-compatible bucket)
                                                          └─► /health  (uptime monitor)   stderr logs → log service
```

> **Status of this guide.** The application code, the PostgreSQL mode, backups/restore (`pg_dump`/`pg_restore`), MFA, branch isolation and all controls were
> tested against a real PostgreSQL 16 server (160 automated checks pass on both PostgreSQL and SQLite). The Docker files and cloud-provider steps below were
> **not** run in this environment (no Docker / cloud account) — do a staging deployment and the disaster-recovery drill before going live.

## 1. What you need

| Component | Requirement |
|---|---|
| App server | Linux VM or container runtime; PHP 8.2 with `pdo_pgsql`, `mbstring`, `curl`, `openssl`, `fileinfo` (the provided `Dockerfile` has them) |
| Database | PostgreSQL 14+ (managed: AWS RDS, Azure Database, Google Cloud SQL, DigitalOcean, Neon, Supabase…). Enable TLS and automated backups with point-in-time recovery |
| TLS | Certificate for your domain (Caddy in `docker-compose.yml` does Let's Encrypt automatically) |
| File storage | Local volume on encrypted disk **or** an S3-compatible *private* bucket |
| Secrets | `APP_KEY`, `DB_PASSWORD`, S3 keys — from your platform's secret manager, never in the repository |

## 2. Option A — Docker Compose on one server (simplest)

```bash
cp .env.example .env          # set DOMAIN, APP_KEY (openssl rand -base64 48), DB_PASSWORD
docker compose up -d --build  # proxy (HTTPS) + app + PostgreSQL
```
Browse to `https://<DOMAIN>/` → the **Setup** page creates the first branch and the System Administrator. The proxy obtains the certificate automatically
(DNS A record must point at the server, ports 80/443 open). Volumes: `pgdata` (database), `uploads` (documents), `backups` (database dumps) — back these up.

## 3. Option B — managed PostgreSQL + any container platform

1. Create the database and an **application role** with `CONNECT`, `CREATE` on its schema and no superuser rights.
   (The append-only triggers protect history from accidental edits, but a superuser could still drop them — keep superuser credentials out of the app.)
2. Set environment variables (see `.env.example`): `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSLMODE=require` (or `verify-full`), `APP_ENV=production`,
   `APP_KEY`, `TRUST_PROXY=1`, `SESSION_DRIVER=db`.
3. Deploy the image behind the platform's HTTPS load balancer. Scale to several instances freely: sessions are stored in the database (`SESSION_DRIVER=db`),
   sequences and posting use row locks, duplicate posting is blocked by unique tokens.
4. Run one instance first: the first request applies the database migrations automatically.

## 4. Option C — plain Linux VM (no containers)

Install Apache or Nginx+PHP-FPM, copy the project to `/var/www/bono`, make `app/`, `database/`, `storage/` unreachable from the web (the shipped `.htaccess` does this for Apache; with
Nginx deny those paths), set the same environment variables (e.g. `/etc/apache2/envvars` or PHP-FPM `env[]`), terminate TLS with Certbot, and apply `deploy/php.ini`.

## 5. Production security checklist

The **System health** page (administrator / COO) lists any item below that is not met.

- [ ] `APP_ENV=production` — forces HTTPS redirect, secure `__Host-` session cookie, HSTS, hides errors.
- [ ] HTTPS everywhere; `TRUST_PROXY=1` only when a trusted proxy sets `X-Forwarded-Proto` / `X-Forwarded-For`.
- [ ] `APP_KEY` set (long random). MFA seeds are AES-256-GCM encrypted with it; losing it forces everyone to re-enrol MFA.
- [ ] Multi-factor authentication required for System Administrator, COO and Branch Manager (default; configurable under Settings → Security & recovery).
- [ ] Database over TLS (`DB_SSLMODE=require`), restricted network access (only the app servers), application role without superuser.
- [ ] Encryption at rest on the database disk / provider setting and on the uploads volume or bucket (private, no public ACL).
- [ ] No demo data (`tools/seed_demo.php` is removed from the production image).
- [ ] Recovery objectives (RTO/RPO) approved by management and recorded in Settings.
- [ ] Dependencies/updates: rebuild the image regularly (`docker compose build --pull`), keep the OS and PostgreSQL patched.

## 6. Backups and disaster recovery

| Layer | Mechanism |
|---|---|
| Database | Provider's automated backups + PITR (recommended primary protection) **and** the application's verified `pg_dump -Fc` backups (every approved EOD, scheduled, manual). Each dump is re-read with `pg_restore --list` before it is reported as successful |
| Documents | Application archives local uploads next to each database backup (`*_files.tar`); with an S3 bucket enable versioning + replication |
| Off-site copy | Copy `/data/backups` to a different account/region daily (object storage lifecycle rule, `rclone`, or provider snapshot) |
| Monitoring | `/health` goes `degraded` when the last verified backup is older than twice the backup interval; the dashboard warns; failed EOD backups are recorded on the EOD |

**Controlled restore:** the System Administrator requests a restore of a specific backup → the **COO authorizes** (separate account, valid 24 h) → the Administrator executes it. The app verifies the
file, takes a *pre-restore safety backup*, restores, re-verifies and logs everything. Executing a restore on a managed database may be done through the provider instead (PITR); record it in the audit trail afterwards.

**Drill (do before go-live and then quarterly):** restore the latest backup into a staging database → open the system → compare client / loan / payment counts → run **Backup & recovery → Data integrity check** → record the elapsed time as your measured RTO.

## 7. Monitoring

* `GET /index.php?p=health` → `{"status":"ok|degraded|down", db, storage, migrations, backup}` (HTTP 503 when down). Point an uptime monitor at it. It reveals no records or configuration.
* Container logs go to stderr (`LOG_TO_STDERR=1`) → your platform's log service. The administrator also sees recent errors on **System health**.
* **Security monitoring** page: failed sign-ins, locked accounts, sign-ins from new IP addresses, MFA coverage. Alert on spikes using your log service or uptime tool.

## 8. Upgrades

Build a new image → deploy (rolling is fine) → the first request applies new migrations (`database/migrations/NNN_*.sql`, once each) → check `/health`. Take a manual backup first.
Never edit an applied migration; add a new numbered file.

## 9. Capacity notes

The application is stateless apart from the database and file storage. A single 2 vCPU / 4 GB app server and a small PostgreSQL instance comfortably serve several branches (thousands of clients,
hundreds of thousands of ledger rows). Scale the app by adding instances; scale the database vertically and keep an eye on connection counts (one short-lived connection per request).
