# Production deployment guide (Step 26)

For the person setting up PocketNyaya's production server. It follows the approved decisions: MariaDB (2026-11-09), hosting in India, private document storage, and no outside service until it is approved.

**Not done yet, and needed before real data:** the PHP 8.4 / Laravel 13 upgrade, a key management service, a malware scanner, the isolation switch-over, counsel and CA review, and a penetration test. See `docs/qa/qa-audit-2026-11-09.md`.

## 1. Server requirements

| Item | Requirement |
|---|---|
| Hosting | A cloud region inside India, for the servers, database, backups and logs |
| OS | Ubuntu 24.04 LTS, patched automatically |
| Web | Nginx, with TLS 1.2 and 1.3 only |
| PHP | PHP-FPM 8.2 now (8.4 after the upgrade milestone). Extensions: pdo_mysql, mbstring, openssl, sodium, zip, intl, bcmath, fileinfo, gd |
| Database | **MariaDB 10.11 or 11.4 LTS**, on its own server or a managed service, private network only |
| Cache, queue, sessions | Redis 7, private network only |
| Disk | Separate encrypted volumes for the app, the documents and the backups |
| Size to start | 2 vCPU and 4 GB RAM for the app; 2 vCPU and 4 GB RAM for MariaDB |

## 2. Production configuration (`.env`)

Values are set on the server only, never committed, and never pasted into chat or tickets.

| Key | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | the `https://` address |
| `APP_KEY` | generated once with `php artisan key:generate`, then stored in the password manager. Losing it makes encrypted fields unreadable |
| `DB_CONNECTION` | `mariadb` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE` | the private database address |
| `DB_USERNAME`, `DB_PASSWORD` | the migration account for deployment; `pn_app` once the isolation switch-over is done |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `DOCUMENT_KEY_DIR` | outside the web root, on the encrypted volume, readable only by the PHP user. Replace it with a key management service before launch |
| `MAIL_*` | a mail provider with Indian data handling, after approval |
| `LOG_CHANNEL` | `daily`, with logs shipped to the monitoring service |
| `DB_ISOLATION` | `false` until both accounts exist (section 4), then `true` |
| `PLATFORM_DB_USERNAME`, `PLATFORM_DB_PASSWORD` | `pn_platform`, for staff screens, jobs and the practice export |
| `PAYMENTS_DRIVER`, `VIDEO_DRIVER`, `ESIGN_DRIVER`, `COURT_DATA_DRIVER`, `AI_DRIVER`, `MESSAGING_DRIVER` | `none` until each service is approved |

## 3. Environment setup

1. Create a `deploy` user that owns the code and a `www-data` PHP-FPM pool, with no shell login for `www-data`.
2. Install the packages from section 1. Enable the firewall: allow 443 and 80 (redirect only) from the internet, and 22 from the office IP only.
3. Deploy the code into a fresh folder per release (section 10).
4. Run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` on the build machine. Only `public/build` is needed on the server.
5. Run `php artisan config:cache`, `route:cache`, `view:cache` and `event:cache`.

## 4. Database deployment

1. Create the database with `utf8mb4` and `utf8mb4_unicode_ci`, with `sql_mode` including `STRICT_TRANS_TABLES`.
2. Create a **migration account** with rights on this database only. The database administrator runs migrations, because they create triggers, views and procedures (C56).
3. Run `php artisan migrate --force`, then the production seeders only: roles, platform, reference data, screening rules and privacy. **Never run `DemoSeeder` in production.**
4. Create the first two Super Admins by hand, then use the propose and approve screen for every other one.
5. Switch on tenant isolation:
   1. Run `php artisan pocketnyaya:isolation-grants > grants.sql`, then replace both password placeholders privately.
   2. Run the file as the administrator. It creates `pn_app` (the views only) and `pn_platform` (base-table data, no DDL).
   3. Check that `pn_privileged_accounts` lists only the administrator account.
   4. Set `DB_USERNAME=pn_app`, `PLATFORM_DB_USERNAME=pn_platform`, both passwords and `DB_ISOLATION=true`. Then run `php artisan config:cache`.
   5. Run future migrations with `php artisan migrate --database=platform --force` as the administrator account.
   6. Smoke test a practice page, a customer page and a staff page. Then confirm that a practice can't open another practice's matter.

## 5. Queue and scheduler

- **Queue worker** under Supervisor: `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600`, with 2 processes, restarted on every deploy with `php artisan queue:restart`.
- **Scheduler**, from cron every minute: `* * * * * cd /var/www/pocketnyaya/current && php artisan schedule:run >> /dev/null 2>&1`. It runs the daily audit digest at 00:30 and the retention dry run at 01:00, both India time.

## 6. Storage permissions

- `storage/` and `bootstrap/cache/` are writable by the PHP user. All other files are read-only to it.
- Documents are stored in `storage/app/private/documents`, encrypted. They are never served by Nginx directly: only the app streams them after a permission check.
- Nginx serves `public/` only. Deny `.env`, `storage`, `vendor`, and every dot-file.

## 7. SSL

- Use Let's Encrypt (certbot) or the cloud provider's certificate, with automatic renewal and an alert 14 days before expiry.
- Redirect all HTTP to HTTPS. The app adds HSTS on HTTPS responses.
- Grade target: A on SSL Labs.

## 8. Backup strategy

| What | How | How often | Kept |
|---|---|---|---|
| Database | `mariadb-dump --single-transaction --routines --triggers --events`, encrypted with GPG before it leaves the server | Nightly, plus binary logs for point-in-time recovery | 35 days, then monthly copies for 12 months |
| Document files | Encrypted volume snapshot, or sync of `storage/app/private/documents` | Nightly | Same as the database |
| Encryption keys | Kept separately from the files, in a key management service or escrow | On every change | As long as any document needs them |
| `.env` and `APP_KEY` | Password manager | On every change | Always |

All copies stay in an Indian region, with one copy in a second region inside India. **Test a full restore every quarter**, and record the result. A backup that hasn't been restored is unproven.

## 9. Monitoring

- Uptime checks on `/up` and `/api/v1/ping` every minute.
- Error tracking, with personal data scrubbed, hosted in India or self-hosted.
- Alerts for: 5xx rate, queue backlog over 5 minutes, disk over 80%, certificate expiry, a failed backup, a failed audit digest, and repeated sign-in failures.
- A daily check that `pocketnyaya:audit-digest` wrote yesterday's anchors.

## 10. Deployment and rollback

**Deploy:**
1. Build the release, and run the tests and static analysis in CI.
2. Upload it to `/var/www/pocketnyaya/releases/<timestamp>` and link the shared `.env` and `storage`.
3. Take a database backup.
4. Run `php artisan down --retry=60`, then `php artisan migrate --force` and the caches.
5. Switch the `current` symlink to the new release, reload PHP-FPM, run `php artisan queue:restart`, then `php artisan up`.
6. Smoke test: sign-in, a practice page, an invoice, a document download and the API ping.

**Roll back:** point `current` at the previous release and reload PHP-FPM. If a migration must be undone, run `php artisan migrate:rollback --step=1` only when it is known to be safe. Otherwise restore the backup from step 3. Schema changes in MariaDB can't be undone by a transaction, so always take the backup first.

## 11. Security hardening

- SSH keys only, no root login, and fail2ban on SSH.
- MariaDB listens on the private network only, with separate accounts per role and no remote root.
- Redis requires a password and listens on the private network only.
- Nginx rate limits for `/login` and `/api/v1/auth/*`, on top of the app's own limits.
- Automatic OS security updates, and a monthly `composer audit` and `npm audit`.
- Staff accounts always use two-step sign-in, which the app enforces.
- Review the audit log weekly, including bind events after the isolation switch-over.

## 12. Production verification checklist

- [ ] `APP_DEBUG=false`, and an error page shows no stack trace
- [ ] `https://` only; HSTS and the other security headers are present
- [ ] `.env`, `storage` and `vendor` return 404 or 403 from the web
- [ ] No demo accounts exist
- [ ] Two Super Admins exist, both with two-step sign-in
- [ ] A tax rule is approved by a second Super Admin, with the CA's rates
- [ ] Queue worker and scheduler are running, and the first audit digest was written
- [ ] The backup ran, and a restore test passed
- [ ] Every integration shows "Switched off" until approved
- [ ] The penetration test findings are closed
