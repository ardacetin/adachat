# Deployment and operations

> How to run Ada Chat in production, keep it running and upgrade it.
> Turkish: [deployment.tr.md](deployment.tr.md).

Ada runs either in Docker (one image, `compose.production.yml`) or directly
on an Ubuntu 24.04 server. Both run the same things:

| Part | What it does |
|---|---|
| nginx | HTTPS, static files, passes requests to PHP-FPM; must not buffer the streamed chat answers |
| PHP-FPM (PHP 8.4) | The application; every streaming answer holds one worker until it ends |
| MySQL 8.4 LTS | All data; the only supported database |
| Redis | Sessions and cache (budgets never depend on it) |
| Scheduler | `php artisan schedule:run` every minute: budget housekeeping, nightly reconciliation and retention clean-up |

There is no queue worker in V1: nothing is processed in the background
besides the scheduler.

## 1. Sizing

The server's real work is waiting for AI providers, so the number of PHP-FPM
workers matters more than CPU. Each chat answer keeps one worker busy while
it streams (typically 5–60 seconds, at most `ADA_PROVIDER_TIMEOUT`, 300 s).

**Rule of thumb:** `pm.max_children` = answers streaming at the busiest
moment + 10 for page loads. Each worker needs about 40–60 MB of memory.

| Active users at peak | Answers streaming at once | `pm.max_children` | Server |
|---|---|---|---|
| up to 100 | ~5 | 16 | 2 vCPU, 4 GB RAM |
| up to 500 | ~20 | 32 | 4 vCPU, 8 GB RAM |
| up to 2,000 | ~60 | 80 | 8 vCPU, 16 GB RAM |

When all workers are busy, new requests wait until one is free and users see
a slow page. The "Parallel answers" setting of the budget policies (Admin →
Budget policies) limits how many answers one user can have running at once. MySQL
needs little: 2 GB of RAM and 20 GB of disk last a long time (messages are
text).

## 2. With Docker

Requirements: Docker Engine 24+ with the Compose plugin, a host nginx (or
Caddy, Apache) for HTTPS.

```bash
git clone https://github.com/ardacetin/adachat.git /opt/ada && cd /opt/ada
git checkout <latest release tag>
cp deploy/env.production.example .env
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
#   → put the output in APP_KEY, and keep a copy offline (see §7)
nano .env                                   # every value marked CHANGE
docker compose -f compose.production.yml up -d --build
docker compose -f compose.production.yml exec app php artisan ada:install
docker compose -f compose.production.yml exec app php artisan ada:user:promote you@example.edu --role=super_admin
docker compose -f compose.production.yml exec app php artisan ada:doctor
```

- The `app` container runs nginx and PHP-FPM as an unprivileged user and
  applies database migrations when it starts. The `scheduler` container runs
  `php artisan schedule:work` from the same image.
- Data lives in the volumes `ada_mysql` (database), `ada_storage` (logos,
  chat attachments, compiled views) and `ada_redis`.
- The application listens on `127.0.0.1:8080`. Put the host's nginx in front
  of it: [`deploy/nginx/ada-docker-proxy.conf`](../deploy/nginx/ada-docker-proxy.conf).
  `TRUSTED_PROXIES=*` is set by the compose file; that is safe because the
  port is not reachable from outside the host. Change it if you publish the
  port differently.
- Workers: `PHP_FPM_MAX_CHILDREN` in `.env` (default 24, see §1).
- Logs: `docker compose -f compose.production.yml logs -f app`, one JSON
  object per line.

## 3. Without Docker (Ubuntu 24.04)

### 3.1 Packages

```bash
sudo add-apt-repository ppa:ondrej/php      # PHP 8.4
sudo apt install nginx redis-server unzip git \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-intl php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-bcmath php8.4-redis php8.4-opcache
```

- **MySQL 8.4:** Ubuntu 24.04 ships MySQL 8.0. Install 8.4 LTS from the MySQL
  APT repository (dev.mysql.com/downloads/repo/apt), or use a managed or
  separate database server. `ada:doctor` warns on older versions.
- **Composer:** getcomposer.org/download.
- **Node.js 22** is needed only to build the front-end (nodesource or nvm). You
  can also build on another machine and copy `public/build`.

Database and user (the time zone is set by Ada per connection):

```sql
CREATE DATABASE ada CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'ada'@'localhost' IDENTIFIED BY '<long random password>';
GRANT ALL PRIVILEGES ON ada.* TO 'ada'@'localhost';
```

### 3.2 Application

```bash
sudo git clone https://github.com/ardacetin/adachat.git /var/www/ada
sudo chown -R $USER:www-data /var/www/ada && cd /var/www/ada
git checkout <latest release tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp deploy/env.production.example .env && nano .env   # every value marked CHANGE
php artisan key:generate                              # then copy APP_KEY offline (§7)
php artisan migrate --force
php artisan storage:link
php artisan ada:install
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan ada:user:promote you@example.edu --role=super_admin
```

### 3.3 PHP-FPM, nginx, scheduler

```bash
sudo cp deploy/php-fpm/ada.conf /etc/php/8.4/fpm/pool.d/ada.conf   # set pm.max_children (§1)
sudo systemctl restart php8.4-fpm
sudo cp deploy/nginx/ada.conf /etc/nginx/sites-available/ada        # replace ai.example.edu
sudo ln -s /etc/nginx/sites-available/ada /etc/nginx/sites-enabled/ada
sudo certbot --nginx -d ai.example.edu                              # or your institution's certificate
sudo nginx -t && sudo systemctl reload nginx
sudo cp deploy/cron/ada /etc/cron.d/ada
sudo -u www-data php artisan ada:doctor                             # after a minute: scheduler OK
```

Important nginx settings (already in the example): `fastcgi_read_timeout
420s` and no buffering for streamed answers — Ada sends
`X-Accel-Buffering: no`, which nginx honours — and `fastcgi_buffer_size 32k`:
Ada's response headers (asset preload links, security policy) are larger
than nginx's default 4–8 KB, which otherwise ends in "502 Bad Gateway" and
`upstream sent too big header` in the nginx error log. Anything else between the
browser and nginx (a load balancer, a WAF, Cloudflare) must not buffer
responses either, or answers appear all at once at the end.

nginx talks to PHP-FPM directly here, so leave `TRUSTED_PROXIES` empty. If a
load balancer terminates HTTPS in front of nginx, set `TRUSTED_PROXIES` to
its address(es): only then are the client address (audit log, rate limits)
and https (secure cookies, HSTS) taken from its `X-Forwarded-*` headers.

### 3.4 Logs

`LOG_CHANNEL=json` writes one JSON object per line to
`storage/logs/ada-YYYY-MM-DD.log` and keeps `LOG_DAILY_DAYS` (14) days; no
logrotate is needed. Prompts, answers and API keys are never logged.

### 3.5 E-mail

Ada e-mails the cap alerts (80 % and 100 % of the institution's monthly cap)
and the monthly usage report (after each month, with CSV attachments; send
one by hand with `php artisan ada:reports:monthly --month=2026-09
--to=you@example.edu`) to the addresses in Admin → Institution →
Notification e-mails. Set an SMTP server in `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.edu
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS="ada@example.edu"
MAIL_FROM_NAME="Ada Chat"
```

Google Workspace: `smtp-relay.gmail.com` (SMTP relay service in Google Admin)
or `smtp.gmail.com` with an app password. Apply the change
(`php artisan optimize`), then use **Send a test e-mail** on the same admin
page. Messages are sent by the scheduler, not by a queue worker. With
`MAIL_MAILER=log` they are only written to the log; `ada:doctor` warns when
addresses are set but mail is not configured.

## 4. Security checklist

- [ ] `php artisan ada:doctor` passes (debug off, https, development login
      off, secure cookies, scheduler, sign-in, providers).
- [ ] Google Admin: 2-step verification is enforced for the users of the SAML
      app (Security → Authentication → 2-step verification). Ada has no
      passwords; the IdP is the only way in.
- [ ] Firewall: only 80 and 443 are open; MySQL and Redis listen on
      localhost or a private network only.
- [ ] `TRUSTED_PROXIES` lists only your own proxies (or is empty).
- [ ] `.env` is readable only by the deploy user and www-data (`chmod 640`).
- [ ] The `APP_KEY` and database password are stored offline (§7).
- [ ] Backups run and a restore has been tried (§5).
- [ ] An uptime monitor checks `https://<host>/up`.

## 5. Backups

What to back up:

| What | Why | How often |
|---|---|---|
| The database | Everything: users, conversations, budgets, usage, settings, encrypted API keys | Nightly |
| `storage/app` | Uploaded logos and favicon; chat attachments (`private/attachments`, deleted with their conversations) | Nightly |
| `.env`, especially `APP_KEY` | Without the key the stored provider API keys cannot be decrypted | Once, and after every change — **offline, separate from the database backups** |

[`deploy/backup.sh`](../deploy/backup.sh) writes a consistent dump
(`--single-transaction`, no locks) and the storage archive into
`/var/backups/ada/<timestamp>/`, checks that the dump is complete and deletes
backups older than `KEEP_DAYS` (14):

```bash
# /etc/cron.d/ada-backup, without Docker
30 2 * * * root ADA_DIR=/var/www/ada /var/www/ada/deploy/backup.sh >> /var/log/ada-backup.log 2>&1
# with Docker
30 2 * * * root cd /opt/ada && ADA_DOCKER=1 deploy/backup.sh >> /var/log/ada-backup.log 2>&1
```

Copy the backup directory to another machine or storage (rsync, restic,
borg). Backups contain conversation content: protect them like the database.

## 6. Restore

1. Install Ada as in §2 or §3 with the **same `APP_KEY`** as before (from the
   offline copy) and stop it: `php artisan down`, or
   `docker compose -f compose.production.yml stop app scheduler`.
2. Load the database:
   ```bash
   gunzip -c database.sql.gz | mysql -u ada -p ada
   # Docker:
   gunzip -c database.sql.gz | docker compose -f compose.production.yml exec -T mysql \
       sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
   ```
3. Restore the files: `tar -C /var/www/ada/storage -xzf storage-app.tar.gz`
   (Docker: `gunzip -c storage-app.tar.gz | docker compose -f compose.production.yml exec -T app tar -C /app/storage -xf -`).
4. Apply migrations if the backup is from an older version, then start:
   `php artisan migrate --force && php artisan optimize && php artisan up`
   (Docker: `docker compose -f compose.production.yml up -d`).
5. `php artisan ada:doctor`, sign in, open a conversation, check Admin →
   Providers shows the keys (••••1234).

Try a restore on a test machine once before going live, and after major
upgrades.

## 7. The application key (APP_KEY)

`APP_KEY` encrypts the provider API keys stored in the database, sessions and
cookies. Generate it once at installation and never change it casually:

- Store it offline (password manager, sealed envelope), separately from the
  database backups. A database backup without its key loses the API keys
  (they can be entered again); a stolen backup together with the key exposes
  them.
- Never put it in Git, tickets or chat.

**Rotating the key** (e.g. after someone who knew it leaves, or if it may have
leaked):

1. Generate a new key: `php artisan key:generate --show`.
2. In `.env`, set `APP_PREVIOUS_KEYS=<old key>` and `APP_KEY=<new key>`; apply
   (`php artisan optimize` and reload PHP-FPM, or
   `docker compose -f compose.production.yml up -d`).
3. Re-encrypt the stored API keys: `php artisan ada:credentials:reencrypt`
   (Docker: `docker compose -f compose.production.yml exec app php artisan ada:credentials:reencrypt`).
   It reports any key it cannot decrypt; enter those again in Admin →
   Providers.
4. Remove `APP_PREVIOUS_KEYS` and apply again. Everyone signs in again.
5. If the key leaked, also rotate the provider API keys at the providers.

## 8. Upgrading

Read the [changelog](../CHANGELOG.md) first: it says when a release needs
anything beyond the steps below. Take a backup (§5).

With Docker:

```bash
cd /opt/ada && git fetch --tags && git checkout <new tag>
docker compose -f compose.production.yml up -d --build   # migrations run on start
docker compose -f compose.production.yml exec app php artisan ada:doctor
```

Without Docker:

```bash
cd /var/www/ada
php artisan down --retry=60          # answers already streaming finish
git fetch --tags && git checkout <new tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
sudo systemctl reload php8.4-fpm     # opcache does not see new files otherwise
php artisan up
php artisan ada:doctor
```

Going back: check out the previous tag and repeat; restore the backup if the
new version's migrations changed data (the changelog says so).

## 9. Monitoring

- `https://<host>/up` answers 200 when the application runs: point an uptime
  monitor at it.
- `php artisan ada:doctor` exits non-zero on a problem; run it from cron or
  your monitoring and alert on failure. It also warns 30 days before the SAML
  certificate expires.
- Admin → Overview shows spending, active users and unusual usage; the audit
  log shows every administrative change.
- Logs: JSON lines (§3.4); search for `AI provider request failed` when users
  report errors.

## 10. Rolling out at an institution

A staged rollout catches configuration and budget mistakes while few people
are affected. Checklist (used for the first rollout at Beykoz University):

**Before the pilot**

- [ ] Installation done; `ada:doctor` passes; backup and restore tried.
- [ ] Google Workspace SAML app: on only for a pilot organizational unit or
      group at first (Google Admin → app → User access).
- [ ] Providers and models added with current prices; aliases with clear
      names and descriptions ("Fast", "Advanced").
- [ ] Groups and policies: a **Pilot** group with its own budget policy;
      the Default group with a cautious limit (or no aliases yet).
- [ ] Usage notice text reviewed by the data protection officer (Admin →
      Privacy); retention periods decided.
- [ ] Branding: name, logos, colours, default language.
- [ ] Support contact and a short user guide announced to the pilot users.

**Pilot (2–4 weeks, 20–50 people from different units)**

- [ ] Weekly: Overview spending vs. expectation, deviations, error rates,
      users at their limit.
- [ ] Collect feedback: models wanted, limits too tight or loose, problems.
- [ ] Adjust prices, aliases, limits; check the reconciliation reports.

**Wider rollout**

- [ ] Open the SAML app to everyone (or the next units); assign aliases to
      the Default group; set the institution-wide limits.
- [ ] Announce: what it is, what data goes where (the usage notice), how
      budgets work, where to get help.
- [ ] First month: watch the Overview daily, then weekly; size PHP-FPM
      from the real peak (§1).
